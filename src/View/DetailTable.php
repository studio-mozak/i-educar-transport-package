<?php

namespace iEducar\Packages\Transport\View;

class DetailTable
{
    public const CELL_STYLE = 'padding: 6px 14px; text-align: left; white-space: nowrap;';

    public const HEADER_COLOR = '#ccdce6';

    public const ROW_COLORS = ['#f5f9fd', '#FFFFFF'];

    public static function html(array $headers, array $rows): string
    {
        $header = implode('', array_map(
            fn (string $label) => self::cell(self::HEADER_COLOR, $label === '' ? '' : '<b>' . e($label) . '</b>', ' role="columnheader"'),
            $headers
        ));

        $body = implode('', array_map(
            fn (array $cells, int $index) => self::row(self::ROW_COLORS[$index % count(self::ROW_COLORS)], $cells),
            array_values($rows),
            array_keys(array_values($rows))
        ));

        return '<div style="width: 0; min-width: 100%; overflow-x: auto;"><table><tr>' . $header . '</tr>' . $body . '</table></div>';
    }

    private static function row(string $color, array $cells): string
    {
        return '<tr>' . implode('', array_map(fn (string $content) => self::cell($color, $content), $cells)) . '</tr>';
    }

    private static function cell(string $color, string $content, string $attributes = ''): string
    {
        return '<td bgcolor="' . $color . '"' . $attributes . ' style="' . self::CELL_STYLE . '">' . $content . '</td>';
    }
}
