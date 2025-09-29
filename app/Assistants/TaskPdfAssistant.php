<?php

namespace App\Assistants;

use Carbon\Carbon;
use App\GeonamesCountry;
use Illuminate\Support\Str;
use App\Assistants\PdfClient;

class TaskPdfAssistant extends PdfClient
{
    const PACKAGE_TYPE_MAP = [
        "EW-Paletten" => "PALLET_OTHER",
        "Ladung" => "CARTON",
        "Stück" => "OTHER",
    ];

    const INCOTERMS = [
        'EXW', 'FCA', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP',
        'FAS', 'FOB', 'CFR', 'CIF'
    ];

    const CONTAINER_TYPE = [
        '20DV', '40DV', '40HC', '45HC', '20HCPW', '40HCPW', '45HCPW',
        '40HR', '20HR', '40NOR', '20NOR', '22G1', '22P1', '22P3',
        '22R1', '22U1', '22T0', '22T5', '2CG1', '42G1', '42P1',
        '42P3', '42R1', '42U1', '45G1', '45R1', '4CG1', '4EG1'
    ];
    /**
     * Checks if the given file contents match the format of this class.
     * 
     * @param array $lines
     * @return bool
     */
    public static function validateFormat(array $lines)
    {
        // Heuristic: Task PDFs typically include these labels
        $mustHaveLabels = [
            'Tournumber:',
            'Load:',
            'Loading sequence:',
            'Unloading sequence:',
        ];

        $found = 0;
        foreach ($mustHaveLabels as $label) {
            if (array_find_key($lines, fn($l) => $l === $label) !== null) {
                $found++;
            }
        }

        return $found >= 3; // conservative threshold
    }

    /**
     * Generates a structured output from PDF file contents.
     * 
     * @param array $lines
     * @param string|null $attachment_filename
     */
    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $tour_li = array_find_key($lines, fn($l) => $l === "Tournumber:");
        $order_reference = null;
        if ($tour_li !== null && isset($lines[$tour_li + 2])) {
            $order_reference = trim($lines[$tour_li + 2], '* ');
        }

        $truck_li = array_find_key($lines, fn($l) => $l === "Truck, trailer:");
        $truck_number = null;
        if ($truck_li !== null && isset($lines[$truck_li + 2])) {
            $truck_number = trim($lines[$truck_li + 2]);
        }

        $vehicle_li = array_find_key($lines, fn($l) => $l === "Vehicle type:");
        $trailer_number = null;
        if ($truck_li !== null && $vehicle_li !== null) {
            $trailer_li = array_find_key($lines, fn($l, $i) => $i > $truck_li && $i < $vehicle_li && preg_match('/^[A-Z]{2}[0-9]{3}( |$)/', $l));
            if ($trailer_li !== null && isset($lines[$trailer_li])) {
                $trailer_number = explode(' ', $lines[$trailer_li], 2)[0] ?? null;
            }
        }

        $transport_numbers = join(' / ', array_filter([$truck_number, $trailer_number]));

        $freight_price = null;
        $freight_currency = null;
        $freight_li = array_find_key($lines, fn($l) => $l === "Freight rate in €:");
        if ($freight_li !== null && isset($lines[$freight_li + 2])) {
            $raw = $lines[$freight_li + 2];
            $freight_currency = preg_replace('/[^a-zA-Z]+/', '', $raw);
            $numeric = preg_replace('/[^0-9,\.]/', '', $raw);
            $freight_price = uncomma($numeric);
        }

        $customer = [
            'side' => 'none',
            'details' => $this->extractDetails($lines)
        ];

        $attachment_filenames = [];
        if ($attachment_filename) {
            $attachment_filenames[] = mb_strtolower($attachment_filename);
        }

        $loading_li = array_find_key($lines, fn($l) => $l === "Loading sequence:");
        $unloading_li = array_find_key($lines, fn($l) => $l === "Unloading sequence:");
        $regards_li = array_find_key($lines, fn($l) => $l === "Best regards");


        $loading_locations = [];
        if ($loading_li !== null && $unloading_li !== null && $unloading_li > $loading_li) {
            $loading_locations = $this->extractLocations(
                array_slice($lines, $loading_li + 1, $unloading_li - 1 - $loading_li)
            );
        }

        $destination_locations = [];
        if ($unloading_li !== null && $regards_li !== null && $regards_li > $unloading_li) {
            $destination_locations = $this->extractLocations(
                array_slice($lines, $unloading_li + 1, $regards_li - 1 - $unloading_li)
            );
        }

        $cargos = $this->extractCargos($lines);
        $incoterms = $this->extractIncoterms($lines);
        $container = $this->extractContainerInfo($lines);

        $data = compact(
            'customer',
            'attachment_filenames',
            'loading_locations',
            'destination_locations',
            'cargos',
            'order_reference',
            'transport_numbers',
            'freight_price',
            'freight_currency',
        );

        if (!empty($incoterms)) {
            $data = array_merge($data, compact('incoterms'));
        }

        if (is_array($container) && array_filter($container, fn($v) => !is_null($v) && $v !== '')) {
            $data = array_merge($data, compact('container'));
        }

        $this->createOrder($data);
    }


    public function extractLocations(array $lines) {
        $index = 0;
        $location_size = 6;
        $output = [];
        while ($index < count($lines)) {
            $location_lines = array_slice($lines, $index, $location_size);
            if (count($location_lines) >= 5) {
                $output[] = $this->extractLocation($location_lines);
            }
            $index += $location_size;
        }
        return $output;
    }

    public function extractLocation(array $lines) {
        $datetime = $lines[2] ?? '';
        $location = $lines[4] ?? '';

        return [
            'company_address' => $this->parseCompanyAddress($location),
            'time' => $this->parseDatetime($datetime),
        ];
    }

    public function parseDatetime(string $datetime) {
        $date_start = null;
        $date_end = null;

        if (preg_match('/^([0-9\.]+) ?([0-9:]+)?-?([0-9:]+)?$/', $datetime, $matches)) {
            $date_start_str = $matches[1] . (($matches[2] ?? null) ? (" " . $matches[2]) : '');
            $date_end_str = $matches[1] . (($matches[3] ?? null) ? (" " . $matches[3]) : '');

            try {
                $date_start = Carbon::parse($date_start_str)->toIsoString();
            } catch (\Throwable $e) {}

            try {
                $date_end = Carbon::parse($date_end_str)->toIsoString();
            } catch (\Throwable $e) {}
        }

        $output = [
            'datetime_from' => $date_start,
            'datetime_to' => $date_end,
        ];

        if (!empty($output['datetime_from']) && $output['datetime_from'] === $output['datetime_to']) {
            unset($output['datetime_to']);
        }

        return $output;
    }

    public function parseCompanyAddress(string $location) {
        if (!preg_match('/^(.+?)\s*, +(.+?)\s*, +([A-Z]{1,2}-?[0-9]{4,}) +(.+)$/ui', $location, $matches)) {
            return [
                'company' => null,
                'title' => null,
                'street_address' => null,
                'city' => null,
                'postal_code' => null,
                'country' => null,
            ];
        }

        $company = $matches[1];
        $street  = $matches[2];
        $postal  = $matches[3];
        $city    = $matches[4];

        $country = preg_replace('/[^A-Z]/ui', '', $postal);
        $country = GeonamesCountry::getIso($country);

        $postal_code = preg_replace('/[^0-9]/ui', '', $postal);

        return [
            'company' => $company,
            'title' => $company,
            'street_address' => $street,
            'city' => $city,
            'postal_code' => $postal_code,
            'country' => $country,
        ];
    }


    public function extractCargos(array $lines) {
        $load_li = array_find_key($lines, fn($l) => $l === "Load:");
        $title = ($load_li !== null && isset($lines[$load_li + 1])) ? $lines[$load_li + 1] : null;

        $amount_li = array_find_key($lines, fn($l) => $l === "Amount:");
        $package_count = null;
        if ($amount_li !== null && isset($lines[$amount_li + 1]) && $lines[$amount_li + 1] !== '') {
            $package_count = uncomma($lines[$amount_li + 1]);
        }

        $unit_li = array_find_key($lines, fn($l) => $l === "Unit:");
        $package_type = null;
        if ($unit_li !== null && isset($lines[$unit_li + 1])) {
            $package_type = $this->mapPackageType($lines[$unit_li + 1]);
        }

        $weight_li = array_find_key($lines, fn($l) => $l === "Weight:");
        $weight = null;
        if ($weight_li !== null && isset($lines[$weight_li + 1]) && $lines[$weight_li + 1] !== '') {
            $weight = uncomma($lines[$weight_li + 1]);
        }

        $ldm_li = array_find_key($lines, fn($l) => $l === "Loadingmeter:");
        $ldm = null;
        if ($ldm_li !== null && isset($lines[$ldm_li + 1]) && $lines[$ldm_li + 1] !== '') {
            $ldm = uncomma($lines[$ldm_li + 1]);
        }

        $load_ref_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Loading reference:"));
        $load_ref = null;
        if ($load_ref_li !== null && isset($lines[$load_ref_li])) {
            $parts = explode(': ', $lines[$load_ref_li], 2);
            $load_ref = $parts[1] ?? null;
        }

        $unload_ref_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Unloading reference:"));
        $unload_ref = null;
        if ($unload_ref_li !== null && isset($lines[$unload_ref_li])) {
            $parts = explode(': ', $lines[$unload_ref_li], 2);
            $unload_ref = $parts[1] ?? null;
        }

        $number = join('; ', array_filter([$load_ref, $unload_ref]));

        return [[
            'title' => $title,
            'number' => $number,
            'package_count' => $package_count ?? 1,
            'package_type' => $package_type,
            'ldm' => $ldm,
            'weight' => $weight,
        ]];
    }

    public function mapPackageType(string $type) {
        $package_type = static::PACKAGE_TYPE_MAP[$type] ?? "PALLET_OTHER";
        return trans("package_type.{$package_type}");
    }

    public function extractIncoterms(array $lines):string{
        $found = [];
        foreach (static::INCOTERMS as $term) {
            $key = array_find_key($lines, function($line) use ($term) {
                return preg_match('/\b' . preg_quote($term, '/') . '\b/i', $line);
            });
    
            if (!is_null($key)) {
                $found[] = strtoupper($term);
            }
        }
    
        return empty($found) ? '' : implode(",",array_values(array_unique($found)));
    }


    public function extractContainerInfo(array $lines): array {
        $container = [
            'container_number' => null,
            'container_type' => null,
            'booking_reference' => null,
            'shipping_line' => null
        ];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            if (preg_match('/[A-Z]{4}\d{7}/', $trimmedLine, $matches)) {
                $container['container_number'] = $matches[0];
            }

            foreach (static::CONTAINER_TYPE as $type) {
                if (stripos($trimmedLine, $type) !== false) {
                    $container['container_type'] = $type;
                    break;
                }
            }

            if (preg_match('/(Booking reference|Shipment|Pervežimo užsakymas Nr\.|Tournumber|*** F)\s*[:#* ]*([\w\d-]+)/i', $trimmedLine, $matches)) {
                $container['booking_reference'] = trim($matches[2]);
            }

            if (preg_match('/(Shipping line|Vežėjas|Dopravce|Forwarder|Access Logistic|Delamode|Skoda|Chronopost|Sappi|SWISS KRONO)\s*:\s*([\w\s\.-]+)/i', $trimmedLine, $matches)) {
                $container['shipping_line'] = trim($matches[2]);
            }
        }

        return $container;
    }

     public  function extractDetails(array $lines): array {
        $info = [
            'company' => null,
            'company_code' => null,
            'vat_code' => null,
            'email' => null,
            'contact_person' => null,
            'street_address' => null,
            'title' => null,
            'city' => null,
            'country' => null,
            'postal_code' => null,
            'comment' => null
        ];    

        // Extract data based on patterns and labels
        $index = find_index('Vežėjas:', $lines, '/Vežėjas:\s*([\w\s\.-]+)/i');
        if ($index !== null && preg_match('/Vežėjas:\s*([\w\s\.-]+)/i', $lines[$index], $matches)) {
            $info['company'] = trim($matches[1]);
        } else {
            $index = find_index('Dopravce / Spediteur / Forwarder:', $lines ,'/:\s*([\w\s\.-]+)/i');
            if ($index !== null && preg_match('/:\s*([\w\s\.-]+)/i', $lines[$index], $matches)) {
                $info['company'] = trim($matches[1]);
            } else {
                $index = find_index('To:', $lines ,'/To:\s*([\w\s\.-]+)/i');
                if ($index !== null && preg_match('/To:\s*([\w\s\.-]+)/i', $lines[$index], $matches)) {
                    $info['company'] = trim($matches[1]);
                }
            }
        }

        $index = find_index('Į. k./Reg. no.', $lines ,'/Į\. k\.\/Reg\. no\.\s*([\w]+)/i');
        if ($index !== null && preg_match('/Į\. k\.\/Reg\. no\.\s*([\w]+)/i', $lines[$index], $matches)) {
            $info['company_code'] = trim($matches[1]);
        } else {
            $index = find_index('NÁLOŽNÍ LIST / VERLADESCHEIN / LOADING LIST', $lines ,'/\d+/');
            if ($index !== null && preg_match('/\d+/', $lines[$index], $matches)) {
                $info['company_code'] = trim($matches[0]);
            }
        }

        $index = find_index('PVM k./VAT No.', $lines ,'/PVM k\.\/VAT No\.\s*([\w]+)/i');
        if ($index !== null && preg_match('/PVM k\.\/VAT No\.\s*([\w]+)/i', $lines[$index], $matches)) {
            $info['vat_code'] = trim($matches[1]);
        } else {
            $index = find_index('DIČ:', $lines,'/DIČ:([\w]+)/i');
            if ($index !== null && preg_match('/DIČ:([\w]+)/i', $lines[$index], $matches)) {
                $info['vat_code'] = trim($matches[1]);
            } else {
                $index = find_index('USt.-ID:', $lines,'/USt\.-ID:\s*([\w]+)/i');
                if ($index !== null && preg_match('/USt\.-ID:\s*([\w]+)/i', $lines[$index], $matches)) {
                    $info['vat_code'] = trim($matches[1]);
                }
            }
        }

        $index = find_index('Email:', $lines,'/Email:\s*([\w@\.-]+)/i');
        if ($index !== null && preg_match('/Email:\s*([\w@\.-]+)/i', $lines[$index], $matches)) {
            $info['email'] = trim($matches[1]);
        } else {
            $index = find_index('El. paštas:', $lines,'/El\. paštas:\s*([\w@\.-]+)/i');
            if ($index !== null && preg_match('/El\. paštas:\s*([\w@\.-]+)/i', $lines[$index], $matches)) {
                $info['email'] = trim($matches[1]);
            }
        }

        $index = find_index('Contactperson:', $lines,'/Contactperson:\s*([\w\s]+)/i');
        if ($index !== null && preg_match('/Contactperson:\s*([\w\s]+)/i', $lines[$index], $matches)) {
            $info['contact_person'] = trim($matches[1]);
        } else {
            $index = find_index('Řidič / Fahrer / Driver:', $lines,'/:\s*([\w\s]+)/i');
            if ($index !== null && preg_match('/:\s*([\w\s]+)/i', $lines[$index], $matches)) {
                $info['contact_person'] = trim($matches[1]);
            } else {
                $index = find_index('Kontaktas:', $lines,'/Kontaktas:\s*([\w\s]+)/i');
                if ($index !== null && preg_match('/Kontaktas:\s*([\w\s]+)/i', $lines[$index], $matches)) {
                    $info['contact_person'] = trim($matches[1]);
                }
            }
        }

        $index = find_index('Pasikrovimo adresas:', $lines,'/Pasikrovimo adresas:\s*([\w\s\.,#-]+)/i');
        if ($index !== null && preg_match('/Pasikrovimo adresas:\s*([\w\s\.,#-]+)/i', $lines[$index], $matches)) {
            $info['street_address'] = trim($matches[1]);
        } else {
            $index = find_index('Pristatymo adresas:', $lines,'/Pristatymo adresas:\s*([\w\s\.,#-]+)/i');
            if ($index !== null && preg_match('/Pristatymo adresas:\s*([\w\s\.,#-]+)/i', $lines[$index], $matches)) {
                $info['street_address'] = trim($matches[1]);
            }
        }

        $index = find_index('F.A.O.:', $lines,'/F\.A\.O\.:?\s*([\w\s]+)/i');
        if ($index !== null && preg_match('/F\.A\.O\.:?\s*([\w\s]+)/i', $lines[$index], $matches)) {
            $info['title'] = trim($matches[1]);
        } else {
            $index = find_index('Za / für / on behalf of', $lines,'/Za \/ für \/ on behalf of\s*([\w\s\.-]+)/i');
            if ($index !== null && preg_match('/Za \/ für \/ on behalf of\s*([\w\s\.-]+)/i', $lines[$index], $matches)) {
                $info['title'] = trim($matches[1]);
            }
        }

        $index = find_index('To:', $lines,'/To:\s*[\w\s,]*(\w{2,})\s*,/i');
        if ($index !== null && preg_match('/To:\s*[\w\s,]*(\w{2,})\s*,/i', $lines[$index], $matches)) {
            $info['city'] = trim($matches[1]);
        } else {
            $index = find_index('Pristatymo adresas:', $lines,'/\w+\s*,\s*(\w{2,})\s*,/i');
            if ($index !== null && preg_match('/\w+\s*,\s*(\w{2,})\s*,/i', $lines[$index], $matches)) {
                $info['city'] = trim($matches[1]);
            }
        }

        $index = find_index('', $lines,'/\b([A-Z]{2})\b/'); // For country
        if ($index !== null && preg_match('/\b([A-Z]{2})\b/', $lines[$index], $matches)) {
            $info['country'] = trim($matches[1]);
        } else {
            $index = find_index('Pristatymo adresas:', $lines,'/,\s*([A-Z]{2})\s*$/i');
            if ($index !== null && preg_match('/,\s*([A-Z]{2})\s*$/i', $lines[$index], $matches)) {
                $info['country'] = trim($matches[1]);
            }
        }

        $index = find_index('Pristatymo adresas:', $lines,'/\d{4,5}\s*[A-Z]*\s*,/');
        if ($index !== null && preg_match('/\d{4,5}\s*[A-Z]*\s*,/', $lines[$index], $matches)) {
            $info['postal_code'] = trim($matches[0]);
        } else {
            $index = find_index('To:', $lines,'/,\s*(\d{4,5}\s*[A-Z]*),/i');
            if ($index !== null && preg_match('/,\s*(\d{4,5}\s*[A-Z]*),/i', $lines[$index], $matches)) {
                $info['postal_code'] = trim($matches[1]);
            }
        }

        $index = find_index('Tournumber:', $lines,'/Tournumber:\s*([\w*]+)/i');
        if ($index !== null && preg_match('/Tournumber:\s*([\w*]+)/i', $lines[$index], $matches)) {
            $info['comment'] = trim($matches[1]);
        } else {
            $index = find_index('Prašome įkelti visus CMR/POD/Pristatymo dokumentus', $lines,'/Prašome.*$/i');
            if ($index !== null && preg_match('/Prašome.*$/i', $lines[$index], $matches)) {
                $info['comment'] = trim($matches[0]);
            }
        }

        // Clean up and return
        return array_filter($info, fn($v) => $v !== null);
    }
}
