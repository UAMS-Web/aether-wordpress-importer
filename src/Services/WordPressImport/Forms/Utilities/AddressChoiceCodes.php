<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities;

/**
 * Translates the country and U.S. state names a form plugin stores for an
 * address default into the codes the host app's `Address` fieldtype keys its
 * selects by.
 *
 * Each list is a `value_translation` index array of `search` and `replace`
 * pairs, so a fieldtype mapping can use it as a translation directly. A
 * `replace` of `null` marks a name with no choice to default to.
 */
class AddressChoiceCodes
{
    /**
     * Country names translated to ISO 3166-1 alpha-2 codes.
     *
     * @var list<array{search: string, replace: string}>
     */
    public const array COUNTRIES = [
        [
            'search' => 'Afghanistan',
            'replace' => 'AF',
        ],
        [
            'search' => 'Albania',
            'replace' => 'AL',
        ],
        [
            'search' => 'Algeria',
            'replace' => 'DZ',
        ],
        [
            'search' => 'American Samoa',
            'replace' => 'AS',
        ],
        [
            'search' => 'Andorra',
            'replace' => 'AD',
        ],
        [
            'search' => 'Angola',
            'replace' => 'AO',
        ],
        [
            'search' => 'Anguilla',
            'replace' => 'AI',
        ],
        [
            'search' => 'Antarctica',
            'replace' => 'AQ',
        ],
        [
            'search' => 'Antigua and Barbuda',
            'replace' => 'AG',
        ],
        [
            'search' => 'Argentina',
            'replace' => 'AR',
        ],
        [
            'search' => 'Armenia',
            'replace' => 'AM',
        ],
        [
            'search' => 'Aruba',
            'replace' => 'AW',
        ],
        [
            'search' => 'Australia',
            'replace' => 'AU',
        ],
        [
            'search' => 'Austria',
            'replace' => 'AT',
        ],
        [
            'search' => 'Azerbaijan',
            'replace' => 'AZ',
        ],
        [
            'search' => 'Bahamas',
            'replace' => 'BS',
        ],
        [
            'search' => 'Bahrain',
            'replace' => 'BH',
        ],
        [
            'search' => 'Bangladesh',
            'replace' => 'BD',
        ],
        [
            'search' => 'Barbados',
            'replace' => 'BB',
        ],
        [
            'search' => 'Belarus',
            'replace' => 'BY',
        ],
        [
            'search' => 'Belgium',
            'replace' => 'BE',
        ],
        [
            'search' => 'Belize',
            'replace' => 'BZ',
        ],
        [
            'search' => 'Benin',
            'replace' => 'BJ',
        ],
        [
            'search' => 'Bermuda',
            'replace' => 'BM',
        ],
        [
            'search' => 'Bhutan',
            'replace' => 'BT',
        ],
        [
            'search' => 'Bolivia',
            'replace' => 'BO',
        ],
        [
            'search' => 'Bonaire, Sint Eustatius and Saba',
            'replace' => 'BQ',
        ],
        [
            'search' => 'Bosnia and Herzegovina',
            'replace' => 'BA',
        ],
        [
            'search' => 'Botswana',
            'replace' => 'BW',
        ],
        [
            'search' => 'Bouvet Island',
            'replace' => 'BV',
        ],
        [
            'search' => 'Brazil',
            'replace' => 'BR',
        ],
        [
            'search' => 'British Indian Ocean Territory',
            'replace' => 'IO',
        ],
        [
            'search' => 'Brunei Darussalam',
            'replace' => 'BN',
        ],
        [
            'search' => 'Bulgaria',
            'replace' => 'BG',
        ],
        [
            'search' => 'Burkina Faso',
            'replace' => 'BF',
        ],
        [
            'search' => 'Burundi',
            'replace' => 'BI',
        ],
        [
            'search' => 'Cabo Verde',
            'replace' => 'CV',
        ],
        [
            'search' => 'Cambodia',
            'replace' => 'KH',
        ],
        [
            'search' => 'Cameroon',
            'replace' => 'CM',
        ],
        [
            'search' => 'Canada',
            'replace' => 'CA',
        ],
        [
            'search' => 'Cayman Islands',
            'replace' => 'KY',
        ],
        [
            'search' => 'Central African Republic',
            'replace' => 'CF',
        ],
        [
            'search' => 'Chad',
            'replace' => 'TD',
        ],
        [
            'search' => 'Chile',
            'replace' => 'CL',
        ],
        [
            'search' => 'China',
            'replace' => 'CN',
        ],
        [
            'search' => 'Christmas Island',
            'replace' => 'CX',
        ],
        [
            'search' => 'Cocos Islands',
            'replace' => 'CC',
        ],
        [
            'search' => 'Colombia',
            'replace' => 'CO',
        ],
        [
            'search' => 'Comoros',
            'replace' => 'KM',
        ],
        [
            'search' => 'Congo',
            'replace' => 'CG',
        ],
        [
            'search' => 'Congo, Democratic Republic of the',
            'replace' => 'CD',
        ],
        [
            'search' => 'Cook Islands',
            'replace' => 'CK',
        ],
        [
            'search' => 'Costa Rica',
            'replace' => 'CR',
        ],
        [
            'search' => 'Croatia',
            'replace' => 'HR',
        ],
        [
            'search' => 'Cuba',
            'replace' => 'CU',
        ],
        [
            'search' => 'Curaçao',
            'replace' => 'CW',
        ],
        [
            'search' => 'Cyprus',
            'replace' => 'CY',
        ],
        [
            'search' => 'Czechia',
            'replace' => 'CZ',
        ],
        [
            'search' => 'Côte dIvoire',
            'replace' => 'CI',
        ],
        [
            'search' => 'Denmark',
            'replace' => 'DK',
        ],
        [
            'search' => 'Djibouti',
            'replace' => 'DJ',
        ],
        [
            'search' => 'Dominica',
            'replace' => 'DM',
        ],
        [
            'search' => 'Dominican Republic',
            'replace' => 'DO',
        ],
        [
            'search' => 'Ecuador',
            'replace' => 'EC',
        ],
        [
            'search' => 'Egypt',
            'replace' => 'EG',
        ],
        [
            'search' => 'El Salvador',
            'replace' => 'SV',
        ],
        [
            'search' => 'Equatorial Guinea',
            'replace' => 'GQ',
        ],
        [
            'search' => 'Eritrea',
            'replace' => 'ER',
        ],
        [
            'search' => 'Estonia',
            'replace' => 'EE',
        ],
        [
            'search' => 'Eswatini',
            'replace' => 'SZ',
        ],
        [
            'search' => 'Ethiopia',
            'replace' => 'ET',
        ],
        [
            'search' => 'Falkland Islands',
            'replace' => 'FK',
        ],
        [
            'search' => 'Faroe Islands',
            'replace' => 'FO',
        ],
        [
            'search' => 'Fiji',
            'replace' => 'FJ',
        ],
        [
            'search' => 'Finland',
            'replace' => 'FI',
        ],
        [
            'search' => 'France',
            'replace' => 'FR',
        ],
        [
            'search' => 'French Guiana',
            'replace' => 'GF',
        ],
        [
            'search' => 'French Polynesia',
            'replace' => 'PF',
        ],
        [
            'search' => 'French Southern Territories',
            'replace' => 'TF',
        ],
        [
            'search' => 'Gabon',
            'replace' => 'GA',
        ],
        [
            'search' => 'Gambia',
            'replace' => 'GM',
        ],
        [
            'search' => 'Georgia',
            'replace' => 'GE',
        ],
        [
            'search' => 'Germany',
            'replace' => 'DE',
        ],
        [
            'search' => 'Ghana',
            'replace' => 'GH',
        ],
        [
            'search' => 'Gibraltar',
            'replace' => 'GI',
        ],
        [
            'search' => 'Greece',
            'replace' => 'GR',
        ],
        [
            'search' => 'Greenland',
            'replace' => 'GL',
        ],
        [
            'search' => 'Grenada',
            'replace' => 'GD',
        ],
        [
            'search' => 'Guadeloupe',
            'replace' => 'GP',
        ],
        [
            'search' => 'Guam',
            'replace' => 'GU',
        ],
        [
            'search' => 'Guatemala',
            'replace' => 'GT',
        ],
        [
            'search' => 'Guernsey',
            'replace' => 'GG',
        ],
        [
            'search' => 'Guinea',
            'replace' => 'GN',
        ],
        [
            'search' => 'Guinea-Bissau',
            'replace' => 'GW',
        ],
        [
            'search' => 'Guyana',
            'replace' => 'GY',
        ],
        [
            'search' => 'Haiti',
            'replace' => 'HT',
        ],
        [
            'search' => 'Heard Island and McDonald Islands',
            'replace' => 'HM',
        ],
        [
            'search' => 'Holy See',
            'replace' => 'VA',
        ],
        [
            'search' => 'Honduras',
            'replace' => 'HN',
        ],
        [
            'search' => 'Hong Kong',
            'replace' => 'HK',
        ],
        [
            'search' => 'Hungary',
            'replace' => 'HU',
        ],
        [
            'search' => 'Iceland',
            'replace' => 'IS',
        ],
        [
            'search' => 'India',
            'replace' => 'IN',
        ],
        [
            'search' => 'Indonesia',
            'replace' => 'ID',
        ],
        [
            'search' => 'Iran',
            'replace' => 'IR',
        ],
        [
            'search' => 'Iraq',
            'replace' => 'IQ',
        ],
        [
            'search' => 'Ireland',
            'replace' => 'IE',
        ],
        [
            'search' => 'Isle of Man',
            'replace' => 'IM',
        ],
        [
            'search' => 'Israel',
            'replace' => 'IL',
        ],
        [
            'search' => 'Italy',
            'replace' => 'IT',
        ],
        [
            'search' => 'Jamaica',
            'replace' => 'JM',
        ],
        [
            'search' => 'Japan',
            'replace' => 'JP',
        ],
        [
            'search' => 'Jersey',
            'replace' => 'JE',
        ],
        [
            'search' => 'Jordan',
            'replace' => 'JO',
        ],
        [
            'search' => 'Kazakhstan',
            'replace' => 'KZ',
        ],
        [
            'search' => 'Kenya',
            'replace' => 'KE',
        ],
        [
            'search' => 'Kiribati',
            'replace' => 'KI',
        ],
        [
            'search' => 'Korea, Democratic Peoples Republic of',
            'replace' => 'KP',
        ],
        [
            'search' => 'Korea, Republic of',
            'replace' => 'KR',
        ],
        [
            'search' => 'Kuwait',
            'replace' => 'KW',
        ],
        [
            'search' => 'Kyrgyzstan',
            'replace' => 'KG',
        ],
        [
            'search' => 'Lao Peoples Democratic Republic',
            'replace' => 'LA',
        ],
        [
            'search' => 'Latvia',
            'replace' => 'LV',
        ],
        [
            'search' => 'Lebanon',
            'replace' => 'LB',
        ],
        [
            'search' => 'Lesotho',
            'replace' => 'LS',
        ],
        [
            'search' => 'Liberia',
            'replace' => 'LR',
        ],
        [
            'search' => 'Libya',
            'replace' => 'LY',
        ],
        [
            'search' => 'Liechtenstein',
            'replace' => 'LI',
        ],
        [
            'search' => 'Lithuania',
            'replace' => 'LT',
        ],
        [
            'search' => 'Luxembourg',
            'replace' => 'LU',
        ],
        [
            'search' => 'Macao',
            'replace' => 'MO',
        ],
        [
            'search' => 'Madagascar',
            'replace' => 'MG',
        ],
        [
            'search' => 'Malawi',
            'replace' => 'MW',
        ],
        [
            'search' => 'Malaysia',
            'replace' => 'MY',
        ],
        [
            'search' => 'Maldives',
            'replace' => 'MV',
        ],
        [
            'search' => 'Mali',
            'replace' => 'ML',
        ],
        [
            'search' => 'Malta',
            'replace' => 'MT',
        ],
        [
            'search' => 'Marshall Islands',
            'replace' => 'MH',
        ],
        [
            'search' => 'Martinique',
            'replace' => 'MQ',
        ],
        [
            'search' => 'Mauritania',
            'replace' => 'MR',
        ],
        [
            'search' => 'Mauritius',
            'replace' => 'MU',
        ],
        [
            'search' => 'Mayotte',
            'replace' => 'YT',
        ],
        [
            'search' => 'Mexico',
            'replace' => 'MX',
        ],
        [
            'search' => 'Micronesia',
            'replace' => 'FM',
        ],
        [
            'search' => 'Moldova',
            'replace' => 'MD',
        ],
        [
            'search' => 'Monaco',
            'replace' => 'MC',
        ],
        [
            'search' => 'Mongolia',
            'replace' => 'MN',
        ],
        [
            'search' => 'Montenegro',
            'replace' => 'ME',
        ],
        [
            'search' => 'Montserrat',
            'replace' => 'MS',
        ],
        [
            'search' => 'Morocco',
            'replace' => 'MA',
        ],
        [
            'search' => 'Mozambique',
            'replace' => 'MZ',
        ],
        [
            'search' => 'Myanmar',
            'replace' => 'MM',
        ],
        [
            'search' => 'Namibia',
            'replace' => 'NA',
        ],
        [
            'search' => 'Nauru',
            'replace' => 'NR',
        ],
        [
            'search' => 'Nepal',
            'replace' => 'NP',
        ],
        [
            'search' => 'Netherlands',
            'replace' => 'NL',
        ],
        [
            'search' => 'New Caledonia',
            'replace' => 'NC',
        ],
        [
            'search' => 'New Zealand',
            'replace' => 'NZ',
        ],
        [
            'search' => 'Nicaragua',
            'replace' => 'NI',
        ],
        [
            'search' => 'Niger',
            'replace' => 'NE',
        ],
        [
            'search' => 'Nigeria',
            'replace' => 'NG',
        ],
        [
            'search' => 'Niue',
            'replace' => 'NU',
        ],
        [
            'search' => 'Norfolk Island',
            'replace' => 'NF',
        ],
        [
            'search' => 'North Macedonia',
            'replace' => 'MK',
        ],
        [
            'search' => 'Northern Mariana Islands',
            'replace' => 'MP',
        ],
        [
            'search' => 'Norway',
            'replace' => 'NO',
        ],
        [
            'search' => 'Oman',
            'replace' => 'OM',
        ],
        [
            'search' => 'Pakistan',
            'replace' => 'PK',
        ],
        [
            'search' => 'Palau',
            'replace' => 'PW',
        ],
        [
            'search' => 'Palestine, State of',
            'replace' => 'PS',
        ],
        [
            'search' => 'Panama',
            'replace' => 'PA',
        ],
        [
            'search' => 'Papua New Guinea',
            'replace' => 'PG',
        ],
        [
            'search' => 'Paraguay',
            'replace' => 'PY',
        ],
        [
            'search' => 'Peru',
            'replace' => 'PE',
        ],
        [
            'search' => 'Philippines',
            'replace' => 'PH',
        ],
        [
            'search' => 'Pitcairn',
            'replace' => 'PN',
        ],
        [
            'search' => 'Poland',
            'replace' => 'PL',
        ],
        [
            'search' => 'Portugal',
            'replace' => 'PT',
        ],
        [
            'search' => 'Puerto Rico',
            'replace' => 'PR',
        ],
        [
            'search' => 'Qatar',
            'replace' => 'QA',
        ],
        [
            'search' => 'Romania',
            'replace' => 'RO',
        ],
        [
            'search' => 'Russian Federation',
            'replace' => 'RU',
        ],
        [
            'search' => 'Rwanda',
            'replace' => 'RW',
        ],
        [
            'search' => 'Réunion',
            'replace' => 'RE',
        ],
        [
            'search' => 'Saint Barthélemy',
            'replace' => 'BL',
        ],
        [
            'search' => 'Saint Helena, Ascension and Tristan da Cunha',
            'replace' => 'SH',
        ],
        [
            'search' => 'Saint Kitts and Nevis',
            'replace' => 'KN',
        ],
        [
            'search' => 'Saint Lucia',
            'replace' => 'LC',
        ],
        [
            'search' => 'Saint Martin',
            'replace' => 'MF',
        ],
        [
            'search' => 'Saint Pierre and Miquelon',
            'replace' => 'PM',
        ],
        [
            'search' => 'Saint Vincent and the Grenadines',
            'replace' => 'VC',
        ],
        [
            'search' => 'Samoa',
            'replace' => 'WS',
        ],
        [
            'search' => 'San Marino',
            'replace' => 'SM',
        ],
        [
            'search' => 'Sao Tome and Principe',
            'replace' => 'ST',
        ],
        [
            'search' => 'Saudi Arabia',
            'replace' => 'SA',
        ],
        [
            'search' => 'Senegal',
            'replace' => 'SN',
        ],
        [
            'search' => 'Serbia',
            'replace' => 'RS',
        ],
        [
            'search' => 'Seychelles',
            'replace' => 'SC',
        ],
        [
            'search' => 'Sierra Leone',
            'replace' => 'SL',
        ],
        [
            'search' => 'Singapore',
            'replace' => 'SG',
        ],
        [
            'search' => 'Sint Maarten',
            'replace' => 'SX',
        ],
        [
            'search' => 'Slovakia',
            'replace' => 'SK',
        ],
        [
            'search' => 'Slovenia',
            'replace' => 'SI',
        ],
        [
            'search' => 'Solomon Islands',
            'replace' => 'SB',
        ],
        [
            'search' => 'Somalia',
            'replace' => 'SO',
        ],
        [
            'search' => 'South Africa',
            'replace' => 'ZA',
        ],
        [
            'search' => 'South Georgia and the South Sandwich Islands',
            'replace' => 'GS',
        ],
        [
            'search' => 'South Sudan',
            'replace' => 'SS',
        ],
        [
            'search' => 'Spain',
            'replace' => 'ES',
        ],
        [
            'search' => 'Sri Lanka',
            'replace' => 'LK',
        ],
        [
            'search' => 'Sudan',
            'replace' => 'SD',
        ],
        [
            'search' => 'Suriname',
            'replace' => 'SR',
        ],
        [
            'search' => 'Svalbard and Jan Mayen',
            'replace' => 'SJ',
        ],
        [
            'search' => 'Sweden',
            'replace' => 'SE',
        ],
        [
            'search' => 'Switzerland',
            'replace' => 'CH',
        ],
        [
            'search' => 'Syria Arab Republic',
            'replace' => 'SY',
        ],
        [
            'search' => 'Taiwan',
            'replace' => 'TW',
        ],
        [
            'search' => 'Tajikistan',
            'replace' => 'TJ',
        ],
        [
            'search' => 'Tanzania, the United Republic of',
            'replace' => 'TZ',
        ],
        [
            'search' => 'Thailand',
            'replace' => 'TH',
        ],
        [
            'search' => 'Timor-Leste',
            'replace' => 'TL',
        ],
        [
            'search' => 'Togo',
            'replace' => 'TG',
        ],
        [
            'search' => 'Tokelau',
            'replace' => 'TK',
        ],
        [
            'search' => 'Tonga',
            'replace' => 'TO',
        ],
        [
            'search' => 'Trinidad and Tobago',
            'replace' => 'TT',
        ],
        [
            'search' => 'Tunisia',
            'replace' => 'TN',
        ],
        [
            'search' => 'Turkmenistan',
            'replace' => 'TM',
        ],
        [
            'search' => 'Turks and Caicos Islands',
            'replace' => 'TC',
        ],
        [
            'search' => 'Tuvalu',
            'replace' => 'TV',
        ],
        [
            'search' => 'Türkiye',
            'replace' => 'TR',
        ],
        [
            'search' => 'US Minor Outlying Islands',
            'replace' => 'UM',
        ],
        [
            'search' => 'Uganda',
            'replace' => 'UG',
        ],
        [
            'search' => 'Ukraine',
            'replace' => 'UA',
        ],
        [
            'search' => 'United Arab Emirates',
            'replace' => 'AE',
        ],
        [
            'search' => 'United Kingdom',
            'replace' => 'GB',
        ],
        [
            'search' => 'United States',
            'replace' => 'US',
        ],
        [
            'search' => 'Uruguay',
            'replace' => 'UY',
        ],
        [
            'search' => 'Uzbekistan',
            'replace' => 'UZ',
        ],
        [
            'search' => 'Vanuatu',
            'replace' => 'VU',
        ],
        [
            'search' => 'Venezuela',
            'replace' => 'VE',
        ],
        [
            'search' => 'Viet Nam',
            'replace' => 'VN',
        ],
        [
            'search' => 'Virgin Islands, British',
            'replace' => 'VG',
        ],
        [
            'search' => 'Virgin Islands, U.S.',
            'replace' => 'VI',
        ],
        [
            'search' => 'Wallis and Futuna',
            'replace' => 'WF',
        ],
        [
            'search' => 'Western Sahara',
            'replace' => 'EH',
        ],
        [
            'search' => 'Yemen',
            'replace' => 'YE',
        ],
        [
            'search' => 'Zambia',
            'replace' => 'ZM',
        ],
        [
            'search' => 'Zimbabwe',
            'replace' => 'ZW',
        ],
        [
            'search' => 'Åland Islands',
            'replace' => 'AX',
        ],
    ];

    /**
     * U.S. state, district and territory names translated to postal codes.
     *
     * The Armed Forces regions have no choice in the host app's American state
     * select, so they translate to `null`.
     *
     * @var list<array{search: string, replace: ?string}>
     */
    public const array AMERICAN_STATES = [
        [
            'search' => 'Alabama',
            'replace' => 'AL',
        ],
        [
            'search' => 'Alaska',
            'replace' => 'AK',
        ],
        [
            'search' => 'American Samoa',
            'replace' => 'AS',
        ],
        [
            'search' => 'Arizona',
            'replace' => 'AZ',
        ],
        [
            'search' => 'Arkansas',
            'replace' => 'AR',
        ],
        [
            'search' => 'California',
            'replace' => 'CA',
        ],
        [
            'search' => 'Colorado',
            'replace' => 'CO',
        ],
        [
            'search' => 'Connecticut',
            'replace' => 'CT',
        ],
        [
            'search' => 'Delaware',
            'replace' => 'DE',
        ],
        [
            'search' => 'District of Columbia',
            'replace' => 'DC',
        ],
        [
            'search' => 'Florida',
            'replace' => 'FL',
        ],
        [
            'search' => 'Georgia',
            'replace' => 'GA',
        ],
        [
            'search' => 'Guam',
            'replace' => 'GU',
        ],
        [
            'search' => 'Hawaii',
            'replace' => 'HI',
        ],
        [
            'search' => 'Idaho',
            'replace' => 'ID',
        ],
        [
            'search' => 'Illinois',
            'replace' => 'IL',
        ],
        [
            'search' => 'Indiana',
            'replace' => 'IN',
        ],
        [
            'search' => 'Iowa',
            'replace' => 'IA',
        ],
        [
            'search' => 'Kansas',
            'replace' => 'KS',
        ],
        [
            'search' => 'Kentucky',
            'replace' => 'KY',
        ],
        [
            'search' => 'Louisiana',
            'replace' => 'LA',
        ],
        [
            'search' => 'Maine',
            'replace' => 'ME',
        ],
        [
            'search' => 'Maryland',
            'replace' => 'MD',
        ],
        [
            'search' => 'Massachusetts',
            'replace' => 'MA',
        ],
        [
            'search' => 'Michigan',
            'replace' => 'MI',
        ],
        [
            'search' => 'Minnesota',
            'replace' => 'MN',
        ],
        [
            'search' => 'Mississippi',
            'replace' => 'MS',
        ],
        [
            'search' => 'Missouri',
            'replace' => 'MO',
        ],
        [
            'search' => 'Montana',
            'replace' => 'MT',
        ],
        [
            'search' => 'Nebraska',
            'replace' => 'NE',
        ],
        [
            'search' => 'Nevada',
            'replace' => 'NV',
        ],
        [
            'search' => 'New Hampshire',
            'replace' => 'NH',
        ],
        [
            'search' => 'New Jersey',
            'replace' => 'NJ',
        ],
        [
            'search' => 'New Mexico',
            'replace' => 'NM',
        ],
        [
            'search' => 'New York',
            'replace' => 'NY',
        ],
        [
            'search' => 'North Carolina',
            'replace' => 'NC',
        ],
        [
            'search' => 'North Dakota',
            'replace' => 'ND',
        ],
        [
            'search' => 'Northern Mariana Islands',
            'replace' => 'MP',
        ],
        [
            'search' => 'Ohio',
            'replace' => 'OH',
        ],
        [
            'search' => 'Oklahoma',
            'replace' => 'OK',
        ],
        [
            'search' => 'Oregon',
            'replace' => 'OR',
        ],
        [
            'search' => 'Pennsylvania',
            'replace' => 'PA',
        ],
        [
            'search' => 'Puerto Rico',
            'replace' => 'PR',
        ],
        [
            'search' => 'Rhode Island',
            'replace' => 'RI',
        ],
        [
            'search' => 'South Carolina',
            'replace' => 'SC',
        ],
        [
            'search' => 'South Dakota',
            'replace' => 'SD',
        ],
        [
            'search' => 'Tennessee',
            'replace' => 'TN',
        ],
        [
            'search' => 'Texas',
            'replace' => 'TX',
        ],
        [
            'search' => 'Utah',
            'replace' => 'UT',
        ],
        [
            'search' => 'U.S. Virgin Islands',
            'replace' => 'VI',
        ],
        [
            'search' => 'Vermont',
            'replace' => 'VT',
        ],
        [
            'search' => 'Virginia',
            'replace' => 'VA',
        ],
        [
            'search' => 'Washington',
            'replace' => 'WA',
        ],
        [
            'search' => 'West Virginia',
            'replace' => 'WV',
        ],
        [
            'search' => 'Wisconsin',
            'replace' => 'WI',
        ],
        [
            'search' => 'Wyoming',
            'replace' => 'WY',
        ],
        [
            'search' => 'Armed Forces Americas',
            'replace' => null,
        ],
        [
            'search' => 'Armed Forces Europe',
            'replace' => null,
        ],
        [
            'search' => 'Armed Forces Pacific',
            'replace' => null,
        ],
    ];
}
