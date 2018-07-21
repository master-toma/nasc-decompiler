<?php

define('BOM', "\xFF\xFE");

class UTF16LEFilter extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing)
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $data = substr($bucket->data, 0, 2) === BOM ? substr($bucket->data, 2) : $bucket->data;
            $bucket->data = iconv('UTF-16LE', 'UTF-8', $data);
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}

function fileJsonDecode(string $file): array
{
    $content = file_get_contents($file);
    $content = preg_replace('#([\s]+//.*)|(^//.*)#', '', $content);
    return json_decode($content, true);
}
