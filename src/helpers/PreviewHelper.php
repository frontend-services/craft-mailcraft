<?php

namespace frontendservices\mailcraft\helpers;

/**
 * Shared helpers for showing rendered email HTML in a preview iframe.
 */
class PreviewHelper
{
    /**
     * Makes rendered email HTML presentable in a preview iframe.
     */
    public static function wrap(?string $html): string
    {
        $html = (string)$html;

        if (preg_match('/<(html|body)[\s>]/i', $html)) {
            return $html;
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . 'body{margin:0;padding:24px;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1f2933;background:#fff;-webkit-font-smoothing:antialiased}'
            . 'img{max-width:100%;height:auto}'
            . '</style></head><body>' . $html . '</body></html>';
    }
}
