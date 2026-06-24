<?php

declare(strict_types=1);

namespace Meteric\Tax\Vies;

/**
 * Result of a qualified VIES check. Beyond valid/invalid it carries VIES's
 * registered name and address plus per-field match flags, so you can warn when
 * the entered company details do not match the VAT registration. The
 * consultationNumber is VIES's request identifier, a record you can keep for an
 * audit. Match flag values are `VALID`, `INVALID`, or `NOT_PROCESSED`.
 */
final class ViesResult
{
    /** @param  array<string,string>  $matches  field => VALID|INVALID|NOT_PROCESSED */
    public function __construct(
        public readonly bool $valid,
        public readonly string $countryCode,
        public readonly string $vatNumber,
        public readonly ?string $requestDate = null,
        public readonly ?string $consultationNumber = null,
        public readonly ?string $name = null,
        public readonly ?string $address = null,
        public readonly array $matches = [],
    ) {}

    /** True when the VAT id is valid and no supplied detail came back as a mismatch. */
    public function detailsMatch(): bool
    {
        return $this->valid && ! in_array('INVALID', $this->matches, true);
    }

    /**
     * The detail fields that did not match the registration (for the warning).
     *
     * @return list<string>
     */
    public function mismatches(): array
    {
        return array_keys(array_filter($this->matches, fn (string $m): bool => $m === 'INVALID'));
    }
}
