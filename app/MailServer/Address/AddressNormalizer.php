<?php

namespace App\MailServer\Address;

final class AddressNormalizer
{
    private const MAX_DOMAIN = 50;

    private const MAX_ADDRESS = 100;

    private const IDNA_OPTIONS = IDNA_DEFAULT | IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI
        | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII;

    public function domain(string $input): DomainName
    {
        $input = $this->trim($input);

        if ($input === '') {
            throw new InvalidAddress('empty');
        }

        // Control characters (NUL included) never belong in a domain.
        if (preg_match('/[\x00-\x20\x7f]/', $input) === 1) {
            throw new InvalidAddress('domain_format');
        }

        $ascii = idn_to_ascii($input, self::IDNA_OPTIONS, INTL_IDNA_VARIANT_UTS46);

        if ($ascii === false || $ascii === '') {
            throw new InvalidAddress('domain_format');
        }

        $ascii = strtolower($ascii);

        $labels = explode('.', $ascii);
        if (count($labels) < 2) {
            throw new InvalidAddress('domain_format');
        }
        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                throw new InvalidAddress('domain_format');
            }
        }

        if (strlen($ascii) > self::MAX_DOMAIN) {
            throw new InvalidAddress('too_long');
        }

        $unicode = idn_to_utf8($ascii, self::IDNA_OPTIONS, INTL_IDNA_VARIANT_UTS46);

        return new DomainName($ascii, $unicode === false ? $ascii : $unicode);
    }

    public function local(string $input): string
    {
        $input = $this->trim($input);

        if ($input === '') {
            throw new InvalidAddress('empty');
        }

        if (preg_match('/[^\x00-\x7f]/', $input) === 1) {
            throw new InvalidAddress('not_ascii');
        }

        $local = strtolower($input);

        if (preg_match('/^[a-z0-9](?:[a-z0-9_-]|\.(?!\.))*$/', $local) !== 1 || str_ends_with($local, '.')) {
            throw new InvalidAddress('local_chars');
        }

        return $local;
    }

    public function address(string $input): EmailAddress
    {
        $input = $this->trim($input);

        if ($input === '') {
            throw new InvalidAddress('empty');
        }

        $at = strrpos($input, '@');
        if ($at === false) {
            throw new InvalidAddress('domain_format');
        }

        $local = $this->local(substr($input, 0, $at));
        $domainPart = substr($input, $at + 1);
        if ($this->trim($domainPart) === '') {
            throw new InvalidAddress('domain_format');
        }

        return $this->addressIn($local, $this->domain($domainPart));
    }

    public function addressIn(string $local, DomainName $domain): EmailAddress
    {
        $address = new EmailAddress($this->local($local), $domain);

        if (strlen($address->address()) > self::MAX_ADDRESS) {
            throw new InvalidAddress('too_long');
        }

        return $address;
    }

    /** Unlike trim(), leaves NUL bytes alone so they are refused later. */
    private function trim(string $input): string
    {
        return trim($input, " \t\n\r\x0B");
    }
}
