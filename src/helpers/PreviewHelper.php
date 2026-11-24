<?php

namespace frontendservices\mailcraft\helpers;

use craft\helpers\Html;
use craft\helpers\Json;

/**
 * Shared helpers for showing rendered email HTML in a preview iframe.
 */
class PreviewHelper
{
    /**
     * Makes rendered email HTML presentable in a preview iframe. `$footer` is trusted HTML
     * placed after the body.
     */
    public static function wrap(?string $html, string $footer = ''): string
    {
        $html = (string)$html;

        if (preg_match('/<(html|body)[\s>]/i', $html)) {
            if ($footer === '') {
                return $html;
            }
            $end = strripos($html, '</body>');

            return $end === false ? $html . $footer : substr($html, 0, $end) . $footer . substr($html, $end);
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . 'body{margin:0;padding:24px;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1f2933;background:#fff;-webkit-font-smoothing:antialiased}'
            . 'img{max-width:100%;height:auto}'
            . '</style></head><body>' . $html . $footer . '</body></html>';
    }

    /**
     * A horizontal rule and a two-column table of a message's `data` values, labelled from the
     * `additionalDataFields` setting (the key when a value has no description). '' when empty.
     *
     * @param array $data key => value
     * @param array $fields key => ['label' => string, ...]
     */
    public static function dataTable(array $data, array $fields): string
    {
        $rows = '';
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (!is_scalar($value)) {
                $value = Json::encode($value);
            }
            $rows .= '<tr>'
                . '<th style="text-align:left;vertical-align:top;font-weight:600;padding:4px 24px 4px 0;white-space:nowrap">'
                . Html::encode((string)($fields[$key]['label'] ?? $key)) . '</th>'
                . '<td style="padding:4px 0;word-break:break-word">' . Html::encode((string)$value) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            return '';
        }

        return '<hr style="border:0;border-top:1px solid #d9e0e7;margin:24px 0 16px">'
            . '<table style="border-collapse:collapse;font:14px/1.5 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1f2933">'
            . $rows . '</table>';
    }
}
