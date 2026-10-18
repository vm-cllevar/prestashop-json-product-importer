<?php
class JsonProductPreviewValidator
{
    public static function parse($json)
    {
        if (!is_string($json) || strlen($json) > 1048576) {
            throw new InvalidArgumentException('JSON must be at most 1 MiB.');
        }
        $data = json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || count($data) < 1 || count($data) > 100) {
            throw new InvalidArgumentException('Provide a valid JSON array containing 1–100 products.');
        }
        $seen = array();
        $rows = array();
        foreach ($data as $i => $item) {
            $errors = array();
            $notes = array();
            $row = $item instanceof stdClass ? get_object_vars($item) : array();
            if (!($item instanceof stdClass)) { $errors[] = 'Product must be an object.'; }
            if (array_key_exists('sku', $row) || array_key_exists('title', $row)) {
                $source = $row;
                $stock = null;
                $suppliers = isset($source['suppliers']) ? $source['suppliers'] : array();
                if (!is_array($suppliers)) {
                    $errors[] = 'suppliers must be an array.';
                    $suppliers = array();
                }
                if (count($suppliers) === 1 && $suppliers[0] instanceof stdClass) {
                    $candidate = isset($suppliers[0]->stock) ? $suppliers[0]->stock : null;
                    if (is_int($candidate) && $candidate >= 0) { $stock = $candidate; }
                    elseif (is_string($candidate) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $candidate) && (float)$candidate <= 2147483647) { $stock = (int)$candidate; }
                    elseif ($candidate !== null) { $notes[] = 'Supplier stock is not a quantity; leave unchanged.'; }
                } elseif (count($suppliers) > 1) {
                    $notes[] = 'Multiple suppliers: no stock aggregation; leave unchanged.';
                }
                $row = array('reference' => isset($source['sku']) ? $source['sku'] : null,
                    'name' => isset($source['title']) ? $source['title'] : null,
                    'price' => isset($source['retail_price']) ? $source['retail_price'] : null,
                    'stock' => $stock);
            }
            if (array_diff(array_keys($row), array('reference', 'name', 'price', 'stock'))) { $errors[] = 'Unsupported fields.'; }
            foreach (array('reference' => 64, 'name' => 128) as $field => $max) {
                $value = isset($row[$field]) ? $row[$field] : null;
                if (!is_string($value) || trim($value) === '' || preg_match_all('/./us', $value) > $max || preg_match('/[<>={}\x00-\x1f\x7f]/u', $value) || trim($value) !== $value) {
                    $errors[] = $field . ' must be plain text, trimmed, and 1–' . $max . ' characters.';
                }
            }
            $price = isset($row['price']) ? $row['price'] : null;
            if ((!is_int($price) && !is_float($price)) || !is_finite((float)$price) || $price < 0 || $price > 999999999 || abs($price - round($price, 6)) > 0.00000001) {
                $errors[] = 'price must be a nonnegative JSON number up to 999999999 with at most 6 decimal places.';
            }
            if (isset($row['stock']) && (!is_int($row['stock']) || $row['stock'] < 0 || $row['stock'] > 2147483647)) {
                $errors[] = 'stock must be a nonnegative integer up to 2147483647.';
            }
            if (isset($row['reference']) && is_string($row['reference'])) {
                $key = function_exists('mb_strtolower') ? mb_strtolower(trim($row['reference']), 'UTF-8') : strtolower(trim($row['reference']));
                if (isset($seen[$key])) {
                    $errors[] = 'Duplicate reference in this file.';
                    $rows[$seen[$key]]['errors'][] = 'Duplicate reference in this file.';
                } else { $seen[$key] = $i; }
            }
            if (!isset($row['stock'])) { $row['stock'] = null; }
            $rows[] = array('data' => $row, 'errors' => $errors, 'notes' => $notes);
        }
        return $rows;
    }
}
