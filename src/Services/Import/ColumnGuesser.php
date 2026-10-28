<?php

namespace Goldnead\Leadhub\Services\Import;

/**
 * Suggests a contact field for each column header. A suggestion, not a
 * decision: the person confirms it on the mapping step, and a header nobody
 * recognises stays unmapped ('') rather than being guessed at.
 */
class ColumnGuesser
{
    /** @var array<string, list<string>> target => normalised header names */
    protected const NAMES = [
        'email' => ['email', 'emailadresse', 'emailaddress', 'mail', 'emailadr'],
        'first_name' => ['vorname', 'firstname', 'givenname', 'first'],
        'last_name' => ['nachname', 'lastname', 'surname', 'familienname', 'familyname', 'last'],
        'full_name' => ['name', 'fullname', 'vollername', 'kontakt'],
        'phone' => ['telefon', 'phone', 'mobil', 'mobile', 'handy', 'tel', 'telefonnummer', 'phonenumber', 'mobilnummer'],
        'company' => ['firma', 'company', 'unternehmen', 'organisation', 'organization', 'arbeitgeber'],
        'postal_code' => ['plz', 'postleitzahl', 'postalcode', 'postcode', 'zip', 'zipcode'],
        'country' => ['land', 'country', 'laendercode', 'countrycode'],
        'status' => ['status'],
        'consent' => ['einwilligung', 'consent', 'optin', 'newsletter', 'subscribed', 'abonniert', 'marketingeinwilligung'],
        'tags' => ['tags', 'tag', 'schlagworte', 'labels', 'gruppen', 'groups', 'kategorien'],
    ];

    /**
     * @param  list<string>  $header
     * @return list<string> one target per column, '' = leave out
     */
    public function guess(array $header): array
    {
        $taken = [];
        $result = [];

        foreach ($header as $name) {
            $key = $this->normalise($name);
            $target = '';

            foreach (self::NAMES as $candidate => $names) {
                if (in_array($key, $names, true) && ! isset($taken[$candidate])) {
                    $target = $candidate;
                    $taken[$candidate] = true;
                    break;
                }
            }

            $result[] = $target;
        }

        return $result;
    }

    protected function normalise(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return preg_replace('/[^a-z0-9]/', '', $name) ?? '';
    }
}
