<?php

namespace App\Services\Catalogue;

use RuntimeException;
use ZipArchive;

/**
 * Minimal CSV/XLSX reader for catalogue imports. XLSX support covers the
 * shared-string and inline-string cell forms produced by Excel and LibreOffice.
 */
final class TabularFileReader
{
    /**
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    public function read(string $absolutePath, string $extension): array
    {
        $extension = strtolower($extension);

        $matrix = match ($extension) {
            'csv', 'txt' => $this->readCsv($absolutePath),
            'xlsx' => $this->readXlsx($absolutePath),
            default => throw new RuntimeException('Unsupported file type. Upload a CSV or XLSX file.'),
        };

        $matrix = array_values(array_filter($matrix, fn (array $row) => $this->rowHasContent($row)));

        if ($matrix === []) {
            throw new RuntimeException('The file does not contain any rows.');
        }

        $headers = $this->normaliseHeaders(array_shift($matrix));
        $rows = [];

        foreach ($matrix as $index => $row) {
            $assoc = [];
            foreach ($headers as $column => $header) {
                $assoc[$header] = trim((string) ($row[$column] ?? ''));
            }

            if (! $this->rowHasContent(array_values($assoc))) {
                continue;
            }

            $assoc['__row_number'] = (string) ($index + 2);
            $rows[] = $assoc;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return list<list<string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        $firstLine = (string) fgets($handle);
        rewind($handle);
        $delimiter = $this->detectDelimiter($firstLine);

        $matrix = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $matrix[] = array_map(fn ($value) => $this->cleanCell((string) $value), $row);
        }

        fclose($handle);

        return $matrix;
    }

    private function detectDelimiter(string $line): string
    {
        $candidates = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($candidates);

        return (string) array_key_first($candidates);
    }

    /**
     * @return list<list<string>>
     */
    private function readXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('XLSX support requires the PHP zip extension.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The XLSX file could not be opened.');
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false && $sharedXml !== '') {
            $xml = simplexml_load_string($sharedXml);
            if ($xml !== false) {
                foreach ($xml->si as $si) {
                    $sharedStrings[] = $this->richText($si);
                }
            }
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheet === false || $sheet === '') {
            throw new RuntimeException('The workbook does not contain a readable first sheet.');
        }

        $xml = simplexml_load_string($sheet);
        if ($xml === false) {
            throw new RuntimeException('The workbook sheet XML is not valid.');
        }

        $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];

        foreach ($xml->xpath('//x:sheetData/x:row') ?: [] as $rowNode) {
            $rowNode->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $cells = [];
            $maxIndex = 0;

            foreach ($rowNode->xpath('x:c') ?: [] as $cell) {
                $cell->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $ref = (string) $cell['r'];
                $column = $this->columnIndex($ref);
                $type = (string) $cell['t'];
                $valueNode = $cell->xpath('x:v')[0] ?? null;
                $value = $valueNode !== null ? (string) $valueNode : '';

                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $inline = $cell->xpath('x:is')[0] ?? null;
                    $value = $inline !== null ? $this->richText($inline) : '';
                }

                $cells[$column] = $this->cleanCell($value);
                $maxIndex = max($maxIndex, $column);
            }

            $row = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $row[$i] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function richText(\SimpleXMLElement $node): string
    {
        $node->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $parts = $node->xpath('.//x:t') ?: [];

        return implode('', array_map(fn ($part) => (string) $part, $parts));
    }

    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?: 'A';
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function cleanCell(string $value): string
    {
        return trim(str_replace(["\xEF\xBB\xBF", "\u{00A0}"], ' ', $value));
    }

    /**
     * @param  list<string>  $row
     */
    private function rowHasContent(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $headers
     * @return list<string>
     */
    private function normaliseHeaders(array $headers): array
    {
        $result = [];
        $seen = [];

        foreach ($headers as $index => $header) {
            $label = trim((string) $header);
            if ($label === '') {
                $label = 'Column '.($index + 1);
            }

            $base = $label;
            $suffix = 2;
            while (isset($seen[strtolower($label)])) {
                $label = $base.' '.$suffix;
                $suffix++;
            }

            $seen[strtolower($label)] = true;
            $result[] = $label;
        }

        return $result;
    }
}
