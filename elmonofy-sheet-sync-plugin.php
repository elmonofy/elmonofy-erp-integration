<?php
/**
 * Plugin Name: Elmonofy Sheet Sync (SKU Merge + Woo Sync)
 * Description: Upload XLSX, normalize SKU data (remove warehouse + merge qty by SKU), then create/update WooCommerce products.
 * Version: 1.0.0
 * Author: Elmonofy
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Elmonofy_Sheet_Sync_Plugin')) {
class Elmonofy_Sheet_Sync_Plugin {
    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
    }

    public function admin_menu() {
        add_menu_page(
            'Elmonofy Sheet Sync',
            'Sheet Sync',
            'manage_woocommerce',
            'elmonofy-sheet-sync',
            [$this, 'render_page'],
            'dashicons-database-import',
            56
        );
    }

    public function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Not allowed');
        }

        $result = null;
        if (!empty($_POST['elmonofy_sheet_sync_submit'])) {
            check_admin_referer('elmonofy_sheet_sync_nonce_action', 'elmonofy_sheet_sync_nonce');
            $mode = sanitize_text_field($_POST['mode'] ?? 'dry');
            $result = $this->handle_upload_and_sync($mode);
        }
        ?>
        <div class="wrap">
            <h1>Elmonofy Sheet Sync</h1>
            <p>Flow: <strong>Upload XLSX → map SKU → remove Warehouse → merge stock by SKU → create/update Woo products</strong></p>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('elmonofy_sheet_sync_nonce_action', 'elmonofy_sheet_sync_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="sheet_file">XLSX File</label></th>
                        <td><input type="file" name="sheet_file" id="sheet_file" accept=".xlsx" required /></td>
                    </tr>
                    <tr>
                        <th>Mode</th>
                        <td>
                            <label><input type="radio" name="mode" value="dry" checked> Dry Run</label><br>
                            <label><input type="radio" name="mode" value="live"> Live (Create/Update)</label>
                        </td>
                    </tr>
                </table>
                <p><button class="button button-primary" type="submit" name="elmonofy_sheet_sync_submit" value="1">Run Sync</button></p>
            </form>

            <?php if ($result): ?>
                <hr>
                <h2>Result</h2>
                <p><strong>Mode:</strong> <?php echo esc_html(strtoupper($result['mode'])); ?></p>
                <p><strong>Total rows parsed:</strong> <?php echo intval($result['parsed_rows']); ?></p>
                <p><strong>Merged unique SKU:</strong> <?php echo intval($result['unique_skus']); ?></p>
                <p><strong>Created:</strong> <?php echo intval($result['created']); ?> | <strong>Updated:</strong> <?php echo intval($result['updated']); ?> | <strong>Failed:</strong> <?php echo intval($result['failed']); ?></p>
                <textarea style="width:100%;height:300px;" readonly><?php echo esc_textarea(implode("\n", $result['logs'])); ?></textarea>
            <?php endif; ?>
        </div>
        <?php
    }

    private function handle_upload_and_sync($mode) {
        $out = [
            'mode' => $mode,
            'parsed_rows' => 0,
            'unique_skus' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'logs' => [],
        ];

        if (empty($_FILES['sheet_file']) || !empty($_FILES['sheet_file']['error'])) {
            $out['failed']++;
            $out['logs'][] = '[FATAL] File upload error';
            return $out;
        }

        $tmp = $_FILES['sheet_file']['tmp_name'];
        $rows = $this->read_xlsx_rows($tmp);
        if (empty($rows)) {
            $out['failed']++;
            $out['logs'][] = '[FATAL] XLSX parse failed or empty sheet';
            return $out;
        }

        try {
            [$mapped, $parsedRows] = $this->normalize_and_merge($rows);
        } catch (Throwable $e) {
            $out['failed']++;
            $out['logs'][] = '[FATAL] ' . $e->getMessage();
            return $out;
        }

        $out['parsed_rows'] = $parsedRows;
        $out['unique_skus'] = count($mapped);

        foreach ($mapped as $item) {
            $sku = $item['sku'];
            try {
                $product_id = wc_get_product_id_by_sku($sku);

                if (!$product_id) {
                    if ($mode === 'live') {
                        $new_id = wp_insert_post([
                            'post_title' => $item['name'],
                            'post_type' => 'product',
                            'post_status' => 'draft',
                        ], true);

                        if (is_wp_error($new_id)) {
                            throw new Exception($new_id->get_error_message());
                        }

                        update_post_meta($new_id, '_sku', $sku);
                        update_post_meta($new_id, '_price', $item['sale_price'] !== '' ? $item['sale_price'] : $item['regular_price']);
                        update_post_meta($new_id, '_regular_price', $item['regular_price']);
                        update_post_meta($new_id, '_sale_price', $item['sale_price']);
                        update_post_meta($new_id, '_manage_stock', 'yes');
                        update_post_meta($new_id, '_stock', $item['stock_quantity']);
                        update_post_meta($new_id, '_stock_status', $item['stock_quantity'] > 0 ? 'instock' : 'outofstock');
                    }

                    $out['created']++;
                    $out['logs'][] = '[CREATE] ' . $sku . ' | ' . $item['name'];
                } else {
                    if ($mode === 'live') {
                        update_post_meta($product_id, '_regular_price', $item['regular_price']);
                        update_post_meta($product_id, '_sale_price', $item['sale_price']);
                        update_post_meta($product_id, '_price', $item['sale_price'] !== '' ? $item['sale_price'] : $item['regular_price']);
                        update_post_meta($product_id, '_manage_stock', 'yes');
                        update_post_meta($product_id, '_stock', $item['stock_quantity']);
                        update_post_meta($product_id, '_stock_status', $item['stock_quantity'] > 0 ? 'instock' : 'outofstock');
                    }

                    $out['updated']++;
                    $out['logs'][] = '[UPDATE] ' . $sku . ' | id=' . $product_id;
                }
            } catch (Throwable $e) {
                $out['failed']++;
                $out['logs'][] = '[FAILED] ' . $sku . ' :: ' . $e->getMessage();
            }
        }

        return $out;
    }

    private function normalize_and_merge(array $rows) {
        // detect header row in first 30 rows (some exports have title/blank rows first)
        $headerRow = 0;
        $idxName = $idxSku = $idxQty = $idxReg = $idxSale = -1;

        $maxScan = min(30, count($rows));
        for ($hr = 0; $hr < $maxScan; $hr++) {
            $headers = array_map('trim', array_map('strval', $rows[$hr] ?? []));
            $tmpName = $this->find_header($headers, ['Item', 'Item Name', 'الصنف', 'صنف', 'Product', 'Name']);
            $tmpSku  = $this->find_header($headers, ['sku', 'SKU', 'Item Barcode', 'باركود الصنف', 'Barcode', 'EAN']);
            if ($tmpName !== -1 && $tmpSku !== -1) {
                $headerRow = $hr;
                $idxName = $tmpName;
                $idxSku  = $tmpSku;
                $idxQty  = $this->find_header($headers, ['Qty', 'Quantity', 'الكمية', 'Stock']);
                $idxReg  = $this->find_header($headers, ['Standard Selling', 'البيع القياسية', 'Regular Price', 'Price']);
                $idxSale = $this->find_header($headers, ['Cash', 'Sale Price', 'After Discount', 'سعر بعد الخصم']);
                break;
            }
        }

        if ($idxSku === -1 || $idxName === -1) {
            throw new Exception('Required headers not found (name/sku).');
        }

        $map = [];
        $parsedRows = 0;

        for ($r = $headerRow + 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $sku = trim((string)($row[$idxSku] ?? ''));
            $name = trim((string)($row[$idxName] ?? ''));
            if ($sku === '' || $name === '') continue;

            $parsedRows++;
            $qty = intval($this->to_number($row[$idxQty] ?? 0));
            $regular = $this->to_price($row[$idxReg] ?? '');
            $sale = $this->to_price($row[$idxSale] ?? '');

            if (!isset($map[$sku])) {
                $map[$sku] = [
                    'name' => $name,
                    'sku' => $sku,
                    'stock_quantity' => 0,
                    'regular_price' => $regular,
                    'sale_price' => $sale,
                ];
            }

            $map[$sku]['stock_quantity'] += $qty;
            if ($map[$sku]['regular_price'] === '' && $regular !== '') $map[$sku]['regular_price'] = $regular;
            if ($map[$sku]['sale_price'] === '' && $sale !== '') $map[$sku]['sale_price'] = $sale;
            if ($map[$sku]['name'] === '' && $name !== '') $map[$sku]['name'] = $name;
        }

        return [array_values($map), $parsedRows];
    }

    private function to_number($v) {
        $v = str_replace(',', '', trim((string)$v));
        return is_numeric($v) ? (float)$v : 0;
    }

    private function to_price($v) {
        $n = $this->to_number($v);
        return $n > 0 ? (string)$n : '';
    }

    private function find_header(array $headers, array $candidates) {
        $norm = array_map(function($h){ return strtolower(trim((string)$h)); }, $headers);
        foreach ($candidates as $c) {
            $i = array_search(strtolower($c), $norm, true);
            if ($i !== false) return (int)$i;
        }
        return -1;
    }

    /**
     * Lightweight XLSX reader (first worksheet only).
     * Returns 2D array rows/cols.
     */
    private function read_xlsx_rows($filePath) {
        if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
            return [];
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($filePath) !== true) {
                return [];
            }

            $sharedStrings = [];
            $ssXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($ssXml !== false) {
                $sx = @simplexml_load_string($ssXml);
                if ($sx && isset($sx->si)) {
                    foreach ($sx->si as $si) {
                        $text = '';
                        if (isset($si->t)) {
                            $text = (string)$si->t;
                        } elseif (isset($si->r)) {
                            foreach ($si->r as $run) {
                                $text .= (string)$run->t;
                            }
                        }
                        $sharedStrings[] = $text;
                    }
                }
            }

            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                $zip->close();
                return [];
            }

            $sheet = @simplexml_load_string($sheetXml);
            $zip->close();
            if (!$sheet || !isset($sheet->sheetData->row)) {
                return [];
            }

            $rows = [];
            foreach ($sheet->sheetData->row as $row) {
                $line = [];
                foreach ($row->c as $c) {
                    $ref = (string)$c['r'];
                    $col = $this->xlsx_col_to_index($ref);
                    $type = (string)$c['t'];
                    $val = '';

                    if ($type === 's') {
                        $idx = intval((string)$c->v);
                        $val = $sharedStrings[$idx] ?? '';
                    } elseif ($type === 'inlineStr') {
                        if (isset($c->is->t)) {
                            $val = (string)$c->is->t;
                        } elseif (isset($c->is->r)) {
                            $tmp = '';
                            foreach ($c->is->r as $run) {
                                $tmp .= (string)$run->t;
                            }
                            $val = $tmp;
                        } else {
                            $val = '';
                        }
                    } else {
                        $val = isset($c->v) ? (string)$c->v : '';
                    }

                    $line[$col] = $val;
                }
                if (!empty($line)) {
                    $maxCol = max(array_keys($line));
                    $normalized = array_fill(0, $maxCol + 1, '');
                    foreach ($line as $i => $v) {
                        $normalized[$i] = $v;
                    }
                    $rows[] = $normalized;
                }
            }

            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function xlsx_col_to_index($cellRef) {
        preg_match('/^[A-Z]+/', $cellRef, $m);
        $letters = $m[0] ?? 'A';
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index - 1;
    }
}
}

new Elmonofy_Sheet_Sync_Plugin();
