<?php

declare(strict_types=1);

namespace Meteric\Invoicing\Drivers;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Meteric\Contracts\InvoiceDriver;
use Meteric\Contracts\TaxResolver;
use Meteric\Enums\CreditState;
use Meteric\Enums\InvoiceState;
use Meteric\Invoicing\CreditNoteDraft;
use Meteric\Invoicing\InvoiceDraft;
use Meteric\Invoicing\IssuedCreditNote;
use Meteric\Invoicing\IssuedInvoice;
use Meteric\Models\Charge;
use Meteric\Models\CreditNote;
use Meteric\Models\Invoice;
use Meteric\Models\InvoiceLine;

/**
 * Canonical local invoice sink. Always available — the default driver. Builds
 * an immutable invoice + lines from the draft's charges, applies tax, and
 * returns an IssuedInvoice. Throws on any failure so charges stay `pending`.
 */
final class DatabaseInvoiceDriver implements InvoiceDriver
{
    public function __construct(private TaxResolver $tax) {}

    public function issue(InvoiceDraft $draft): IssuedInvoice
    {
        return DB::transaction(function () use ($draft): IssuedInvoice {
            $invoice = Invoice::create([
                'account_id' => $draft->account->id,
                'customer_type' => $draft->account->owner_type,
                'customer_id' => $draft->account->owner_id,
                'driver' => 'database',
                'state' => InvoiceState::Draft,
                'currency' => $draft->currency,
                'idempotency_key' => $draft->idempotencyKey,
            ]);

            // Attach the draft's charges so rebuildLines reads them as the source.
            foreach ($draft->charges as $charge) {
                $charge->forceFill(['invoice_id' => $invoice->id])->save();
            }

            $invoice->setRelation('charges', $draft->charges->values());
            $this->rebuildLines($invoice);

            // Financials are set while still draft, then frozen by flipping to open.
            $invoice->forceFill([
                'number' => $this->nextNumber(),
                'state' => InvoiceState::Open,
                'issued_at' => now(),
            ])->save();

            return new IssuedInvoice(
                invoiceId: $invoice->id,
                number: $invoice->number,
            );
        });
    }

    /**
     * Rebuild a Draft invoice's lines from its currently attached charges.
     * Deletes the existing lines, rebuilds them (itemized or consolidated per
     * config('meteric.invoice.line_mode')), and recomputes the invoice totals.
     * Only valid while the invoice is Draft (the trigger freezes issued lines).
     */
    public function rebuildLines(Invoice $invoice): void
    {
        if ($invoice->state !== InvoiceState::Draft) {
            throw new \LogicException('Cannot rebuild lines of a non-draft invoice.');
        }

        $taxContext = $invoice->account->taxContext();
        $currency = $invoice->currency;

        $invoice->lines()->delete();

        $charges = $invoice->charges()->orderBy('created_at')->get();

        $subtotal = Money::ofMinor(0, $currency);
        $taxTotal = Money::ofMinor(0, $currency);
        $sort = 0;

        $consolidated = config('meteric.invoice.line_mode') === 'consolidated';

        foreach ($this->lineGroups($charges, $consolidated) as $group) {
            $base = $this->baseCharge($group);
            $net = Money::ofMinor((int) $group->sum('amount_minor'), $currency);
            $taxResult = $this->tax->resolve($net, $taxContext);

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'charge_id' => $base->id,
                'kind' => $base->kind,
                'title' => $base->title,
                'group' => $base->group,
                'line_group' => $base->line_group,
                'description' => $consolidated ? $this->consolidatedDescription($base, $group) : $base->description,
                'quantity' => $base->quantity,
                'unit' => $base->unit,
                'unit_minor' => $consolidated && $group->count() > 1 ? null : $base->unit_minor,
                'unit_rate' => $consolidated && $group->count() > 1 ? null : $base->unit_rate,
                'amount_minor' => $net->getMinorAmount()->toInt(),
                'tax_rate' => $taxResult->rate,
                'tax_minor' => $taxResult->amount->getMinorAmount()->toInt(),
                'tax_label' => $taxResult->label,
                'currency' => $currency,
                'covers' => $base->covers,
                'dimension_id' => $base->dimension_id,
                'metadata' => $consolidated ? $this->consolidatedMetadata($base, $group) : $base->metadata,
                'sort' => $sort++,
            ]);

            $subtotal = $subtotal->plus($net);
            $taxTotal = $taxTotal->plus($taxResult->amount);
        }

        $total = $subtotal->plus($taxTotal);

        $invoice->forceFill([
            'subtotal_minor' => $subtotal->getMinorAmount()->toInt(),
            'tax_minor' => $taxTotal->getMinorAmount()->toInt(),
            'total_minor' => $total->getMinorAmount()->toInt(),
        ])->save();
    }

    /**
     * Finalize a Draft invoice: assign a number when missing, flip to open, and
     * stamp issued_at. Sends the invoice's current lines as-is (no rebuild).
     */
    public function finalize(Invoice $invoice): IssuedInvoice
    {
        $invoice->forceFill([
            'number' => $invoice->number ?? $this->nextNumber(),
            'state' => InvoiceState::Open,
            'issued_at' => $invoice->issued_at ?? now(),
        ])->save();

        return new IssuedInvoice(
            invoiceId: $invoice->id,
            number: $invoice->number,
            externalId: $invoice->external_id,
            externalUrl: $invoice->external_url,
        );
    }

    /**
     * Split the draft charges into line groups. Itemized: one group per charge.
     * Consolidated: charges sharing a non-null line_group fold into one group
     * (a product with its options/addons); charges with a null line_group each
     * stay their own group. Order is preserved from the draft.
     *
     * @param  Collection<int,Charge>  $charges
     * @return list<Collection<int,Charge>>
     */
    private function lineGroups(Collection $charges, bool $consolidated): array
    {
        if (! $consolidated) {
            return $charges->map(fn (Charge $c): Collection => collect([$c]))->all();
        }

        /** @var list<Collection<int,Charge>> $groups */
        $groups = [];
        /** @var array<string,int> $index */
        $index = [];
        foreach ($charges as $charge) {
            $key = $charge->line_group;
            if ($key === null || $key === '') {
                $groups[] = collect([$charge]);

                continue;
            }
            if (! isset($index[$key])) {
                $index[$key] = count($groups);
                $groups[] = collect();
            }
            $groups[$index[$key]]->push($charge);
        }

        return $groups;
    }

    /**
     * The parent charge of a group: the base-line kind (LineKind::isBaseLine),
     * or the first charge when none qualifies.
     *
     * @param  Collection<int,Charge>  $group
     */
    private function baseCharge(Collection $group): Charge
    {
        return $group->first(fn (Charge $c): bool => $c->kind->isBaseLine())
            ?? $group->first();
    }

    /**
     * The base description followed by each sub-item as its own line (title or
     * description plus the formatted amount), so a plain-text invoice shows the
     * folded options/addons under the product line.
     *
     * @param  Collection<int,Charge>  $group
     */
    private function consolidatedDescription(Charge $base, Collection $group): ?string
    {
        $lines = [];
        if ($base->description !== null && $base->description !== '') {
            $lines[] = $base->description;
        }

        foreach ($group as $charge) {
            if ($charge->id === $base->id) {
                continue;
            }
            $label = $charge->description ?? $charge->title ?? $charge->kind->value;
            $lines[] = sprintf('%s: %s', $label, $this->formatMoney($charge->money()));
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * A locale-free "CUR 12.34" rendering of a sub-item amount for the folded
     * description. No intl dependency: scale the major amount to 2 decimals.
     */
    private function formatMoney(Money $money): string
    {
        $amount = $money->getAmount()->toScale(2, RoundingMode::HALF_UP)->__toString();

        return $money->getCurrency()->getCurrencyCode().' '.$amount;
    }

    /**
     * The base metadata merged with a structured `items` array of the sub-items
     * (kind, title, description, amount_minor), so a template can render them
     * without parsing the description text.
     *
     * @param  Collection<int,Charge>  $group
     * @return array<string,mixed>
     */
    private function consolidatedMetadata(Charge $base, Collection $group): array
    {
        $items = [];
        foreach ($group as $charge) {
            if ($charge->id === $base->id) {
                continue;
            }
            $items[] = [
                'kind' => $charge->kind->value,
                'title' => $charge->title,
                'description' => $charge->description,
                'amount_minor' => $charge->amount_minor,
            ];
        }

        $metadata = $base->metadata ?? [];
        if ($items !== []) {
            $metadata['items'] = $items;
        }

        return $metadata;
    }

    public function void(IssuedInvoice $invoice): void
    {
        Invoice::whereKey($invoice->invoiceId)->update(['state' => InvoiceState::Void]);
    }

    public function creditNote(IssuedInvoice $invoice, CreditNoteDraft $draft): IssuedCreditNote
    {
        $model = Invoice::findOrFail($invoice->invoiceId);

        // Credit the given net amount at the invoice's tax rate, so the note
        // reverses the same VAT the invoice charged.
        $rate = (float) ($model->lines->max('tax_rate') ?? 0);
        $net = $draft->amount->getMinorAmount()->toInt();

        $note = CreditNote::create([
            'invoice_id' => $model->id,
            'driver' => 'database',
            'state' => CreditState::Issued,
            'reason' => $draft->reason,
            'amount_minor' => $net,
            'tax_minor' => (int) round($net * $rate),
            'currency' => $draft->amount->getCurrency()->getCurrencyCode(),
            'number' => $this->nextNumber('CN'),
            'issued_at' => now(),
        ]);

        return new IssuedCreditNote(creditNoteId: $note->id, number: $note->number);
    }

    private function nextNumber(string $prefix = 'INV'): string
    {
        $year = now()->year;
        $seq = (int) DB::table('meteric_invoices')->whereYear('created_at', $year)->count() + 1;

        return sprintf('%s-%d-%06d', $prefix, $year, $seq);
    }
}
