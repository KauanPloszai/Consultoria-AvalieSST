<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/reporting.php';

require_admin();

if (request_method() !== 'GET') {
    send_json(405, [
        'success' => false,
        'message' => 'Metodo nao permitido.',
    ]);
}

function export_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function export_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

function export_sections_meta(): array
{
    return [
        'cover' => 'Capa e Índice',
        'summary' => 'Resumo Executivo e Métricas Principais',
        'sectorAnalysis' => 'Análise por Setor',
        'heatmap' => 'Matriz de Risco Geral',
        'actionPlan' => 'Plano de Ação Recomendado',
    ];
}

function export_parse_sections(array $input): array
{
    $allowed = array_keys(export_sections_meta());
    $raw = trim((string) ($input['sections'] ?? ''));

    if ($raw === '') {
        return $allowed;
    }

    $requested = array_filter(array_map('trim', explode(',', $raw)));
    $normalized = [];

    foreach ($allowed as $section) {
        if (in_array($section, $requested, true)) {
            $normalized[] = $section;
        }
    }

    return $normalized !== [] ? $normalized : $allowed;
}

function export_build_page_sequence(array $sections): array
{
    $pages = [];
    $currentPage = 1;

    if (in_array('cover', $sections, true)) {
        $pages[] = ['key' => 'cover', 'pageNumber' => $currentPage++];
        $pages[] = ['key' => 'index', 'pageNumber' => $currentPage++];
    }

    foreach ($sections as $section) {
        if ($section === 'cover') {
            continue;
        }

        $pages[] = ['key' => $section, 'pageNumber' => $currentPage++];
    }

    return $pages;
}

function export_selected_sector_names(array $payload): array
{
    $availableSectors = array_values(array_filter(array_map(static function (array $sector): string {
        return trim((string) ($sector['name'] ?? ''));
    }, $payload['options']['sectors'] ?? [])));

    $selectedIds = array_map('intval', $payload['filters']['sectorIds'] ?? []);

    if ($selectedIds === []) {
        return $availableSectors;
    }

    $names = [];

    foreach (($payload['options']['sectors'] ?? []) as $sector) {
        if (in_array((int) ($sector['id'] ?? 0), $selectedIds, true)) {
            $names[] = trim((string) ($sector['name'] ?? ''));
        }
    }

    return array_values(array_filter($names));
}

function export_format_decimal(float $value): string
{
    return number_format($value, 2, ',', '.');
}

function export_format_int(int|float $value): string
{
    return number_format((float) $value, 0, ',', '.');
}

function export_join_values(array $items, string $fallback = 'Todos os setores selecionados'): string
{
    $normalized = array_values(array_filter(array_map(static function ($item): string {
        return trim((string) $item);
    }, $items)));

    if ($normalized === []) {
        return $fallback;
    }

    return implode(', ', $normalized);
}

function export_sheet_name(string $name): string
{
    $normalized = preg_replace('/[\\\\\\/*?:\\[\\]]/', ' ', $name) ?? $name;
    $normalized = trim(preg_replace('/\\s+/', ' ', $normalized) ?? $normalized);

    if ($normalized === '') {
        $normalized = 'Planilha';
    }

    return mb_substr($normalized, 0, 31, 'UTF-8');
}

function export_xlsx_column_letter(int $columnIndex): string
{
    $letter = '';

    while ($columnIndex > 0) {
        $modulo = ($columnIndex - 1) % 26;
        $letter = chr(65 + $modulo) . $letter;
        $columnIndex = (int) floor(($columnIndex - 1) / 26);
    }

    return $letter;
}

function export_xlsx_cell(int $rowIndex, int $columnIndex, array $cell): string
{
    $reference = export_xlsx_column_letter($columnIndex) . $rowIndex;
    $style = (int) ($cell['style'] ?? 0);
    $value = $cell['value'] ?? '';
    $type = (string) ($cell['type'] ?? 'string');

    if ($type === 'number' && is_numeric($value)) {
        return '<c r="' . $reference . '" s="' . $style . '"><v>' . str_replace(',', '.', (string) $value) . '</v></c>';
    }

    return '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . export_xml_escape((string) $value) . '</t></is></c>';
}

function export_xlsx_sheet_xml(array $rows, array $columnWidths = []): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
    $xml .= '<sheetFormatPr defaultRowHeight="18"/>';

    if ($columnWidths !== []) {
        $xml .= '<cols>';

        foreach ($columnWidths as $index => $width) {
            $column = $index + 1;
            $xml .= '<col min="' . $column . '" max="' . $column . '" width="' . (float) $width . '" customWidth="1"/>';
        }

        $xml .= '</cols>';
    }

    $xml .= '<sheetData>';

    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 1;
        $xml .= '<row r="' . $excelRow . '">';

        foreach ($row as $columnIndex => $cell) {
            if (!is_array($cell)) {
                continue;
            }

            $xml .= export_xlsx_cell($excelRow, $columnIndex + 1, $cell);
        }

        $xml .= '</row>';
    }

    $xml .= '</sheetData>';
    $xml .= '</worksheet>';

    return $xml;
}

function export_zip_dos_time(int $timestamp): array
{
    $date = getdate($timestamp);
    $year = max(1980, (int) $date['year']);
    $month = (int) $date['mon'];
    $day = (int) $date['mday'];
    $hour = (int) $date['hours'];
    $minute = (int) $date['minutes'];
    $second = (int) floor(((int) $date['seconds']) / 2);

    $dosTime = ($hour << 11) | ($minute << 5) | $second;
    $dosDate = (($year - 1980) << 9) | ($month << 5) | $day;

    return [$dosTime, $dosDate];
}

function export_zip_create(array $files): string
{
    $data = '';
    $centralDirectory = '';
    $offset = 0;
    $entries = 0;
    [$dosTime, $dosDate] = export_zip_dos_time(time());
    $flags = 0x0800;

    foreach ($files as $name => $contents) {
        $fileName = str_replace('\\', '/', (string) $name);
        $body = (string) $contents;
        $size = strlen($body);
        $crc = (int) sprintf('%u', crc32($body));

        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            $flags,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            strlen($fileName),
            0
        );

        $data .= $localHeader . $fileName . $body;

        $centralHeader = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            $flags,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            strlen($fileName),
            0,
            0,
            0,
            0,
            0,
            $offset
        );

        $centralDirectory .= $centralHeader . $fileName;
        $offset = strlen($data);
        $entries++;
    }

    $endOfCentralDirectory = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        $entries,
        $entries,
        strlen($centralDirectory),
        strlen($data),
        0
    );

    return $data . $centralDirectory . $endOfCentralDirectory;
}

function export_xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="5">'
        . '<font><sz val="11"/><color rgb="FF243044"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF405066"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><sz val="11"/><color rgb="FF5E6C81"/><name val="Calibri"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="6">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF2A7BFF"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF273346"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF2FF"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFD"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFD8E2EF"/></left><right style="thin"><color rgb="FFD8E2EF"/></right><top style="thin"><color rgb="FFD8E2EF"/></top><bottom style="thin"><color rgb="FFD8E2EF"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="7">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

function export_xlsx_content_types(array $worksheets): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
    $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

    foreach ($worksheets as $index => $worksheet) {
        $xml .= '<Override PartName="/xl/worksheets/sheet' . ($index + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }

    $xml .= '</Types>';

    return $xml;
}

function export_xlsx_root_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
}

function export_xlsx_workbook_xml(array $worksheets): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<fileVersion appName="xl"/>';
    $xml .= '<workbookPr defaultThemeVersion="124226"/>';
    $xml .= '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="22000" windowHeight="12000"/></bookViews>';
    $xml .= '<sheets>';

    foreach ($worksheets as $index => $worksheet) {
        $sheetIndex = $index + 1;
        $xml .= '<sheet name="' . export_xml_escape(export_sheet_name((string) $worksheet['name'])) . '" sheetId="' . $sheetIndex . '" r:id="rId' . $sheetIndex . '"/>';
    }

    $xml .= '</sheets>';
    $xml .= '</workbook>';

    return $xml;
}

function export_xlsx_workbook_rels(array $worksheets): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

    foreach ($worksheets as $index => $worksheet) {
        $sheetIndex = $index + 1;
        $xml .= '<Relationship Id="rId' . $sheetIndex . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetIndex . '.xml"/>';
    }

    $xml .= '<Relationship Id="rId' . (count($worksheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $xml .= '</Relationships>';

    return $xml;
}

function export_build_excel_rows(array $payload, array $sections): array
{
    $worksheets = [];
    $summary = $payload['summary'] ?? [];
    $company = $payload['company'] ?? [];
    $sectorNames = export_selected_sector_names($payload);
    $statusBreakdown = $payload['statusBreakdown'] ?? [];
    $distribution = $payload['distribution'] ?? ['low' => 0, 'medium' => 0, 'high' => 0];

    if (in_array('cover', $sections, true)) {
        $coverRows = [
            [
                ['value' => 'Relatorio AvalieSST', 'style' => 1],
            ],
            [],
            [
                ['value' => 'Empresa', 'style' => 3],
                ['value' => (string) ($company['name'] ?? 'Empresa'), 'style' => 4],
            ],
            [
                ['value' => 'Periodo', 'style' => 3],
                ['value' => (string) ($summary['periodLabel'] ?? 'Todo o historico'), 'style' => 4],
            ],
            [
                ['value' => 'Emitido em', 'style' => 3],
                ['value' => (string) ($summary['emittedAt'] ?? ''), 'style' => 4],
            ],
            [
                ['value' => 'Formulario ativo', 'style' => 3],
                ['value' => (string) (($company['activeFormName'] ?? '') ?: 'Nao vinculado'), 'style' => 4],
            ],
            [
                ['value' => 'Setores considerados', 'style' => 3],
                ['value' => export_join_values($sectorNames), 'style' => 4],
            ],
            [],
            [
                ['value' => 'Secoes selecionadas', 'style' => 2],
            ],
        ];

        foreach (export_build_page_sequence($sections) as $entry) {
            if (in_array((string) $entry['key'], ['cover', 'index'], true)) {
                continue;
            }

            $coverRows[] = [
                ['value' => (string) (export_sections_meta()[$entry['key']] ?? $entry['key']), 'style' => 4],
                ['value' => (int) $entry['pageNumber'], 'style' => 4, 'type' => 'number'],
            ];
        }

        $worksheets[] = [
            'name' => 'Capa',
            'widths' => [28, 42, 18, 18, 18],
            'rows' => $coverRows,
        ];
    }

    if (in_array('summary', $sections, true)) {
        $summaryRows = [
            [
                ['value' => 'Resumo Executivo', 'style' => 1],
            ],
            [],
            [
                ['value' => 'Indicador', 'style' => 2],
                ['value' => 'Valor', 'style' => 2],
            ],
            [
                ['value' => 'Indice global de risco', 'style' => 3],
                ['value' => (int) ($summary['riskIndex'] ?? 0), 'style' => 4, 'type' => 'number'],
            ],
            [
                ['value' => 'Nivel de risco', 'style' => 3],
                ['value' => (string) ($summary['riskLabel'] ?? 'Sem dados'), 'style' => 4],
            ],
            [
                ['value' => 'Participacao', 'style' => 3],
                ['value' => (int) ($summary['participationRate'] ?? 0), 'style' => 4, 'type' => 'number'],
            ],
            [
                ['value' => 'Taxa de conformidade', 'style' => 3],
                ['value' => (int) ($summary['complianceRate'] ?? 0), 'style' => 4, 'type' => 'number'],
            ],
            [
                ['value' => 'Sessoes totais', 'style' => 3],
                ['value' => (int) ($summary['totalSessions'] ?? 0), 'style' => 4, 'type' => 'number'],
            ],
            [
                ['value' => 'Sessoes concluidas', 'style' => 3],
                ['value' => (int) ($summary['completedSessions'] ?? 0), 'style' => 4, 'type' => 'number'],
            ],
            [
                ['value' => 'Setores criticos', 'style' => 3],
                ['value' => (int) ($summary['criticalSectorsCount'] ?? 0), 'style' => 4, 'type' => 'number'],
            ],
            [],
            [
                ['value' => 'Status das coletas', 'style' => 2],
            ],
            [
                ['value' => 'Status', 'style' => 3],
                ['value' => 'Quantidade', 'style' => 3],
            ],
        ];

        foreach ($statusBreakdown as $status) {
            $summaryRows[] = [
                ['value' => (string) ($status['label'] ?? 'Status'), 'style' => 4],
                ['value' => (int) ($status['value'] ?? 0), 'style' => 4, 'type' => 'number'],
            ];
        }

        $summaryRows[] = [];
        $summaryRows[] = [
            ['value' => 'Distribuicao de risco', 'style' => 2],
        ];
        $summaryRows[] = [
            ['value' => 'Nivel', 'style' => 3],
            ['value' => 'Quantidade', 'style' => 3],
        ];
        $summaryRows[] = [
            ['value' => 'Risco Baixo', 'style' => 4],
            ['value' => (int) ($distribution['low'] ?? 0), 'style' => 4, 'type' => 'number'],
        ];
        $summaryRows[] = [
            ['value' => 'Risco Moderado', 'style' => 4],
            ['value' => (int) ($distribution['medium'] ?? 0), 'style' => 4, 'type' => 'number'],
        ];
        $summaryRows[] = [
            ['value' => 'Risco Alto', 'style' => 4],
            ['value' => (int) ($distribution['high'] ?? 0), 'style' => 4, 'type' => 'number'],
        ];

        $worksheets[] = [
            'name' => 'Resumo Executivo',
            'widths' => [32, 20, 18, 18, 18],
            'rows' => $summaryRows,
        ];
    }

    if (in_array('sectorAnalysis', $sections, true)) {
        $sectorRows = [
            [
                ['value' => 'Analise por Setor', 'style' => 1],
            ],
            [],
            [
                ['value' => 'Setor', 'style' => 2],
                ['value' => 'Colaboradores', 'style' => 2],
                ['value' => 'Media', 'style' => 2],
                ['value' => 'Respostas', 'style' => 2],
                ['value' => 'Funcoes', 'style' => 2],
                ['value' => 'Risco', 'style' => 2],
            ],
        ];

        foreach (($payload['sectorBreakdown'] ?? []) as $sector) {
            $sectorRows[] = [
                ['value' => (string) ($sector['name'] ?? 'Setor'), 'style' => 4],
                ['value' => (int) ($sector['employees'] ?? 0), 'style' => 4, 'type' => 'number'],
                ['value' => (float) ($sector['average'] ?? 0), 'style' => 4, 'type' => 'number'],
                ['value' => (int) ($sector['answerCount'] ?? 0), 'style' => 4, 'type' => 'number'],
                ['value' => count($sector['functions'] ?? []), 'style' => 4, 'type' => 'number'],
                ['value' => (string) ($sector['riskLabel'] ?? 'Sem dados'), 'style' => 4],
            ];
        }

        $worksheets[] = [
            'name' => 'Analise por Setor',
            'widths' => [26, 14, 12, 12, 12, 18],
            'rows' => $sectorRows,
        ];
    }

    if (in_array('heatmap', $sections, true)) {
        $questionRows = [
            [
                ['value' => 'Perguntas Criticas e Heatmap', 'style' => 1],
            ],
            [],
            [
                ['value' => 'Posicao', 'style' => 2],
                ['value' => 'Pergunta', 'style' => 2],
                ['value' => 'Setor', 'style' => 2],
                ['value' => 'Media', 'style' => 2],
                ['value' => 'Score', 'style' => 2],
                ['value' => 'Risco', 'style' => 2],
            ],
        ];

        foreach (($payload['heatmapItems'] ?? []) as $index => $item) {
            $questionRows[] = [
                ['value' => $index + 1, 'style' => 4, 'type' => 'number'],
                ['value' => (string) ($item['text'] ?? 'Pergunta'), 'style' => 4],
                ['value' => (string) (($item['sectorName'] ?? '') ?: 'Empresa'), 'style' => 4],
                ['value' => (float) ($item['average'] ?? 0), 'style' => 4, 'type' => 'number'],
                ['value' => (int) ($item['score'] ?? 0), 'style' => 4, 'type' => 'number'],
                ['value' => (string) ($item['riskLabel'] ?? 'Sem dados'), 'style' => 4],
            ];
        }

        $worksheets[] = [
            'name' => 'Matriz de Risco',
            'widths' => [10, 50, 18, 12, 12, 16],
            'rows' => $questionRows,
        ];
    }

    if (in_array('actionPlan', $sections, true)) {
        $actionRows = [
            [
                ['value' => 'Plano de Acao', 'style' => 1],
            ],
            [],
            [
                ['value' => 'Fator', 'style' => 2],
                ['value' => 'Setor', 'style' => 2],
                ['value' => 'Acao recomendada', 'style' => 2],
                ['value' => 'Prazo', 'style' => 2],
                ['value' => 'Prioridade', 'style' => 2],
            ],
        ];

        foreach (($payload['actionPlan'] ?? []) as $item) {
            $actionRows[] = [
                ['value' => (string) ($item['factor'] ?? 'Fator'), 'style' => 4],
                ['value' => (string) ($item['sectorName'] ?? 'Empresa'), 'style' => 4],
                ['value' => (string) ($item['recommendedAction'] ?? ''), 'style' => 4],
                ['value' => (string) ($item['deadline'] ?? ''), 'style' => 4],
                ['value' => (string) ($item['priorityLabel'] ?? ''), 'style' => 4],
            ];
        }

        $worksheets[] = [
            'name' => 'Plano de Acao',
            'widths' => [34, 22, 56, 16, 20],
            'rows' => $actionRows,
        ];
    }

    if ($worksheets === []) {
        $worksheets[] = [
            'name' => 'Relatorio',
            'widths' => [30, 50],
            'rows' => [
                [
                    ['value' => 'Relatorio AvalieSST', 'style' => 1],
                ],
                [
                    ['value' => 'Nenhuma secao selecionada para exportacao.', 'style' => 4],
                ],
            ],
        ];
    }

    return $worksheets;
}

function export_build_xlsx(array $worksheets): string
{
    $files = [
        '[Content_Types].xml' => export_xlsx_content_types($worksheets),
        '_rels/.rels' => export_xlsx_root_rels(),
        'xl/workbook.xml' => export_xlsx_workbook_xml($worksheets),
        'xl/_rels/workbook.xml.rels' => export_xlsx_workbook_rels($worksheets),
        'xl/styles.xml' => export_xlsx_styles_xml(),
    ];

    foreach ($worksheets as $index => $worksheet) {
        $files['xl/worksheets/sheet' . ($index + 1) . '.xml'] = export_xlsx_sheet_xml(
            $worksheet['rows'] ?? [],
            $worksheet['widths'] ?? []
        );
    }

    return export_zip_create($files);
}

function export_pdf_badge(string $label, string $slug): string
{
    $normalizedSlug = trim(strtolower($slug));
    $class = match ($normalizedSlug) {
        'low', 'green' => 'is-low',
        'medium', 'blue' => 'is-medium',
        'high', 'red' => 'is-high',
        default => 'is-neutral',
    };

    return '<span class="pdf-pill ' . $class . '">' . export_escape($label) . '</span>';
}

function export_pdf_build_index_rows(array $pageSequence): string
{
    $meta = export_sections_meta();
    $rows = '';
    $indexNumber = 1;

    foreach ($pageSequence as $entry) {
        if (in_array((string) $entry['key'], ['cover', 'index'], true)) {
            continue;
        }

        $label = $meta[$entry['key']] ?? (string) $entry['key'];
        $rows .= '<div class="pdf-index-row"><span>' . $indexNumber . '. ' . export_escape($label) . '</span><strong>' . str_pad((string) $entry['pageNumber'], 2, '0', STR_PAD_LEFT) . '</strong></div>';
        $indexNumber++;
    }

    return $rows;
}

function export_pdf_build_heatmap_markup(array $items): string
{
    if ($items === []) {
        return '<div class="pdf-empty">Nao ha respostas suficientes para montar a matriz de risco.</div>';
    }

    $markers = '';

    foreach ($items as $item) {
        $probability = max(1, min(5, (int) ($item['probability'] ?? 1)));
        $impact = max(1, min(5, (int) ($item['impact'] ?? 1)));
        $top = (5 - $impact) * 56 + 10;
        $left = ($probability - 1) * 56 + 10;

        $markers .= '<span class="pdf-heatmap-marker" style="top:' . $top . 'px; left:' . $left . 'px;">' . (int) ($item['rank'] ?? 0) . '</span>';
    }

    return '
      <div class="pdf-heatmap-wrap">
        <span class="pdf-heatmap-axis pdf-heatmap-axis--left">IMPACTO</span>
        <div class="pdf-heatmap-grid">
          <span class="pdf-heatmap-cell pdf-y1">5</span>
          <span class="pdf-heatmap-cell pdf-y2">10</span>
          <span class="pdf-heatmap-cell pdf-o1">15</span>
          <span class="pdf-heatmap-cell pdf-r1">20</span>
          <span class="pdf-heatmap-cell pdf-r2">25</span>

          <span class="pdf-heatmap-cell pdf-g1">4</span>
          <span class="pdf-heatmap-cell pdf-y1">8</span>
          <span class="pdf-heatmap-cell pdf-y2">12</span>
          <span class="pdf-heatmap-cell pdf-o1">16</span>
          <span class="pdf-heatmap-cell pdf-r1">20</span>

          <span class="pdf-heatmap-cell pdf-g2">3</span>
          <span class="pdf-heatmap-cell pdf-g1">6</span>
          <span class="pdf-heatmap-cell pdf-y1">9</span>
          <span class="pdf-heatmap-cell pdf-y2">12</span>
          <span class="pdf-heatmap-cell pdf-o1">15</span>

          <span class="pdf-heatmap-cell pdf-g3">2</span>
          <span class="pdf-heatmap-cell pdf-g2">4</span>
          <span class="pdf-heatmap-cell pdf-g1">6</span>
          <span class="pdf-heatmap-cell pdf-y1">8</span>
          <span class="pdf-heatmap-cell pdf-y2">10</span>

          <span class="pdf-heatmap-cell pdf-g4">1</span>
          <span class="pdf-heatmap-cell pdf-g3">2</span>
          <span class="pdf-heatmap-cell pdf-g2">3</span>
          <span class="pdf-heatmap-cell pdf-g1">4</span>
          <span class="pdf-heatmap-cell pdf-y1">5</span>
          ' . $markers . '
        </div>
        <span class="pdf-heatmap-axis pdf-heatmap-axis--bottom">PROBABILIDADE</span>
      </div>
    ';
}

function export_pdf_build_question_rows(array $items): string
{
    if ($items === []) {
        return '<div class="pdf-empty">Nenhuma pergunta consolidada para os filtros selecionados.</div>';
    }

    $rows = '';

    foreach ($items as $index => $item) {
        $rows .= '
          <div class="pdf-list-row">
            <div class="pdf-list-row__rank">' . ($index + 1) . '</div>
            <div class="pdf-list-row__copy">
              <strong>' . export_escape((string) ($item['text'] ?? 'Pergunta')) . '</strong>
              <span>' . export_escape((string) (($item['sectorName'] ?? '') ?: 'Empresa')) . '</span>
            </div>
            <div class="pdf-list-row__meta">
              <strong>' . export_format_decimal((float) ($item['average'] ?? 0)) . '</strong>
              ' . export_pdf_badge((string) ($item['riskLabel'] ?? 'Sem dados'), (string) ($item['riskSlug'] ?? 'neutral')) . '
            </div>
          </div>
        ';
    }

    return '<div class="pdf-list-card">' . $rows . '</div>';
}

function export_pdf_render(array $payload, array $sections): string
{
    $summary = $payload['summary'] ?? [];
    $company = $payload['company'] ?? [];
    $sectorNames = export_selected_sector_names($payload);
    $pageSequence = export_build_page_sequence($sections);
    $distribution = $payload['distribution'] ?? ['low' => 0, 'medium' => 0, 'high' => 0];
    $statusBreakdown = $payload['statusBreakdown'] ?? [];
    $questionRankings = array_slice($payload['questionRankings'] ?? [], 0, 5);
    $actionPlan = $payload['actionPlan'] ?? [];
    $heatmapItems = $payload['heatmapItems'] ?? [];
    $pagesMarkup = '';

    foreach ($pageSequence as $entry) {
        $key = (string) $entry['key'];
        $pageNumber = (int) $entry['pageNumber'];

        if ($key === 'cover') {
            $pagesMarkup .= '
              <section class="page page--cover">
                <div class="page__topmeta">USO INTERNO / CONFIDENCIAL</div>
                <div class="pdf-brand">
                  <img src="../img/logo.png" alt="AvalieSST" />
                </div>
                <span class="pdf-kicker">RELATORIO DE DIAGNOSTICOS</span>
                <h1>Avaliacao de Riscos Psicossociais no Trabalho</h1>
                <span class="pdf-line"></span>
                <div class="pdf-cover-meta">
                  <div><span>ORGANIZACAO AVALIADA</span><strong>' . export_escape((string) ($company['name'] ?? 'Empresa')) . '</strong></div>
                  <div><span>PERIODO DE REFERENCIA</span><strong>' . export_escape((string) ($summary['periodLabel'] ?? 'Todo o historico')) . '</strong></div>
                  <div><span>FORMULARIO ATIVO</span><strong>' . export_escape((string) (($company['activeFormName'] ?? '') ?: 'Nao vinculado')) . '</strong></div>
                  <div><span>DATA DE EMISSAO</span><strong>' . export_escape((string) ($summary['emittedAt'] ?? '')) . '</strong></div>
                </div>
                <div class="pdf-footer">
                  <div>
                    <strong>Sistema AvalieSST</strong>
                    <span>Documento consolidado com base nas respostas reais do sistema.</span>
                  </div>
                  <span>Pagina ' . $pageNumber . '</span>
                </div>
              </section>
            ';
            continue;
        }

        if ($key === 'index') {
            $pagesMarkup .= '
              <section class="page">
                <h2 class="pdf-section-title">Indice</h2>
                <div class="pdf-index">' . export_pdf_build_index_rows($pageSequence) . '</div>
                <div class="pdf-note-card">
                  <strong>Escopo do relatorio</strong>
                  <p>Empresa: <strong>' . export_escape((string) ($company['name'] ?? 'Empresa')) . '</strong> | Periodo: <strong>' . export_escape((string) ($summary['periodLabel'] ?? 'Todo o historico')) . '</strong></p>
                  <p>Setores considerados: ' . export_escape(export_join_values($sectorNames)) . '</p>
                </div>
                <span class="page__number">Pagina ' . $pageNumber . '</span>
              </section>
            ';
            continue;
        }

        if ($key === 'summary') {
            $statusRows = '';

            foreach ($statusBreakdown as $status) {
                $statusRows .= '<tr><td>' . export_escape((string) ($status['label'] ?? 'Status')) . '</td><td>' . export_format_int((int) ($status['value'] ?? 0)) . '</td></tr>';
            }

            $pagesMarkup .= '
              <section class="page">
                <h2 class="pdf-section-title">Resumo Executivo</h2>
                <p class="pdf-paragraph">Esta secao apresenta as metricas consolidadas das respostas salvas, refletindo participacao, conformidade, risco medio e pontos de maior atencao.</p>
                <div class="pdf-metrics">
                  <article class="pdf-metric"><span>INDICE GLOBAL DE RISCO</span><strong>' . export_format_int((int) ($summary['riskIndex'] ?? 0)) . '/100</strong><em>' . export_escape((string) ($summary['riskLabel'] ?? 'Sem dados')) . '</em></article>
                  <article class="pdf-metric"><span>PARTICIPACAO</span><strong>' . export_format_int((int) ($summary['participationRate'] ?? 0)) . '%</strong><em>' . export_format_int((int) ($summary['completedSessions'] ?? 0)) . ' sessoes concluidas</em></article>
                  <article class="pdf-metric"><span>CONFORMIDADE</span><strong>' . export_format_int((int) ($summary['complianceRate'] ?? 0)) . '%</strong><em>' . export_format_int((int) ($summary['totalSessions'] ?? 0)) . ' sessoes no periodo</em></article>
                  <article class="pdf-metric"><span>SETORES CRITICOS</span><strong>' . export_format_int((int) ($summary['criticalSectorsCount'] ?? 0)) . '</strong><em>Demandam acao prioritaria</em></article>
                </div>
                <div class="pdf-grid-2">
                  <article class="pdf-card">
                    <h3>Status das coletas</h3>
                    <table class="pdf-table"><tbody>' . $statusRows . '</tbody></table>
                  </article>
                  <article class="pdf-card">
                    <h3>Distribuicao de risco</h3>
                    <div class="pdf-distribution-list">
                      <div><span>Risco Baixo</span><strong>' . export_format_int((int) ($distribution['low'] ?? 0)) . '</strong></div>
                      <div><span>Risco Moderado</span><strong>' . export_format_int((int) ($distribution['medium'] ?? 0)) . '</strong></div>
                      <div><span>Risco Alto</span><strong>' . export_format_int((int) ($distribution['high'] ?? 0)) . '</strong></div>
                    </div>
                  </article>
                </div>
                <div class="pdf-card pdf-card--spaced">
                  <h3>Perguntas com maior media</h3>
                  ' . export_pdf_build_question_rows($questionRankings) . '
                </div>
                <span class="page__number">Pagina ' . $pageNumber . '</span>
              </section>
            ';
            continue;
        }

        if ($key === 'sectorAnalysis') {
            $sectorRows = '';

            foreach (($payload['sectorBreakdown'] ?? []) as $sector) {
                $sectorRows .= '
                  <tr>
                    <td>' . export_escape((string) ($sector['name'] ?? 'Setor')) . '</td>
                    <td>' . export_format_int((int) ($sector['employees'] ?? 0)) . '</td>
                    <td>' . export_format_decimal((float) ($sector['average'] ?? 0)) . '</td>
                    <td>' . export_format_int((int) ($sector['answerCount'] ?? 0)) . '</td>
                    <td>' . export_format_int(count($sector['functions'] ?? [])) . '</td>
                    <td>' . export_pdf_badge((string) ($sector['riskLabel'] ?? 'Sem dados'), (string) ($sector['riskSlug'] ?? 'neutral')) . '</td>
                  </tr>
                ';
            }

            if ($sectorRows === '') {
                $sectorRows = '<tr><td colspan="6" class="pdf-empty-cell">Nenhum setor possui respostas consolidadas para o filtro atual.</td></tr>';
            }

            $pagesMarkup .= '
              <section class="page">
                <h2 class="pdf-section-title">Analise por Setor</h2>
                <p class="pdf-paragraph">A tabela abaixo mostra os setores filtrados e o consolidado real das respostas vinculadas a empresa selecionada.</p>
                <div class="pdf-note-card">
                  <strong>Setores considerados</strong>
                  <p>' . export_escape(export_join_values($sectorNames)) . '</p>
                </div>
                <div class="pdf-card pdf-card--spaced">
                  <table class="pdf-table">
                    <thead>
                      <tr>
                        <th>Setor</th>
                        <th>Colaboradores</th>
                        <th>Media</th>
                        <th>Respostas</th>
                        <th>Funcoes</th>
                        <th>Risco</th>
                      </tr>
                    </thead>
                    <tbody>' . $sectorRows . '</tbody>
                  </table>
                </div>
                <span class="page__number">Pagina ' . $pageNumber . '</span>
              </section>
            ';
            continue;
        }

        if ($key === 'heatmap') {
            $mappedFactors = '';

            foreach ($heatmapItems as $item) {
                $mappedFactors .= '
                  <div class="pdf-factor-row">
                    <i>' . (int) ($item['rank'] ?? 0) . '</i>
                    <div>
                      <strong>' . export_escape((string) ($item['text'] ?? 'Pergunta')) . '</strong>
                      <span>' . export_escape((string) (($item['sectorName'] ?? '') ?: 'Empresa')) . ' | Probabilidade: ' . (int) ($item['probability'] ?? 0) . ' | Impacto: ' . (int) ($item['impact'] ?? 0) . ' | Score: ' . (int) ($item['score'] ?? 0) . '</span>
                    </div>
                  </div>
                ';
            }

            if ($mappedFactors === '') {
                $mappedFactors = '<div class="pdf-empty">Nenhum fator mapeado com base nas respostas atuais.</div>';
            }

            $pagesMarkup .= '
              <section class="page">
                <h2 class="pdf-section-title">Matriz de Risco Geral</h2>
                <p class="pdf-paragraph">A matriz cruza recorrencia e impacto percebido. Os itens posicionados mais acima e a direita devem ser acompanhados primeiro.</p>
                ' . export_pdf_build_heatmap_markup($heatmapItems) . '
                <div class="pdf-card pdf-card--spaced">
                  <h3>Fatores mapeados</h3>
                  <div class="pdf-factor-list">' . $mappedFactors . '</div>
                </div>
                <span class="page__number">Pagina ' . $pageNumber . '</span>
              </section>
            ';
            continue;
        }

        if ($key === 'actionPlan') {
            $actionRows = '';

            foreach ($actionPlan as $item) {
                $actionRows .= '
                  <tr>
                    <td>' . export_escape((string) ($item['factor'] ?? 'Fator')) . '</td>
                    <td>' . export_escape((string) ($item['sectorName'] ?? 'Empresa')) . '</td>
                    <td>' . export_escape((string) ($item['recommendedAction'] ?? '')) . '</td>
                    <td>' . export_escape((string) ($item['deadline'] ?? '')) . '</td>
                    <td>' . export_pdf_badge((string) ($item['priorityLabel'] ?? 'Monitorar'), (string) ($item['prioritySlug'] ?? 'neutral')) . '</td>
                  </tr>
                ';
            }

            if ($actionRows === '') {
                $actionRows = '<tr><td colspan="5" class="pdf-empty-cell">Nenhuma ação foi gerada para os filtros selecionados.</td></tr>';
            }

            $pagesMarkup .= '
              <section class="page">
                <h2 class="pdf-section-title">Plano de Ação Recomendado</h2>
                <p class="pdf-paragraph">As ações abaixo seguem o consolidado atual de respostas. Quando existir plano manual salvo, ele será priorizado no relatório.</p>
                <div class="pdf-card pdf-card--spaced">
                  <table class="pdf-table">
                    <thead>
                      <tr>
                        <th>Fator</th>
                        <th>Setor</th>
                        <th>Ação recomendada</th>
                        <th>Prazo</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>' . $actionRows . '</tbody>
                  </table>
                </div>
                <div class="pdf-signature-card">
                  <strong>Assinaturas e validação</strong>
                  <p>Documento gerado a partir dos dados consolidados do sistema AvalieSST para apoio à tomada de decisão.</p>
                  <div class="pdf-signatures">
                    <div><span></span><strong>Responsável SST</strong><small>' . export_escape((string) ($company['name'] ?? 'Empresa')) . '</small></div>
                    <div><span></span><strong>Diretoria</strong><small>' . export_escape((string) ($company['name'] ?? 'Empresa')) . '</small></div>
                  </div>
                </div>
                <span class="page__number">Pagina ' . $pageNumber . '</span>
              </section>
            ';
        }
    }

    return '<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Relatorio AvalieSST</title>
  <style>
    :root {
      color-scheme: light;
    }
    * {
      box-sizing: border-box;
    }
    body {
      margin: 0;
      padding: 24px;
      background: #eef3fb;
      color: #162033;
      font-family: Arial, sans-serif;
    }
    .page {
      position: relative;
      width: min(100%, 840px);
      min-height: 1120px;
      margin: 0 auto 24px;
      padding: 28px 30px 34px;
      background: #ffffff;
      border: 1px solid #dfe7f2;
      border-radius: 18px;
      box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
      break-after: page;
      page-break-after: always;
      page-break-inside: avoid;
    }
    .page:last-child {
      break-after: auto;
      page-break-after: auto;
      margin-bottom: 0;
    }
    .page--cover {
      min-height: 1120px;
      padding-top: 24px;
      display: flex;
      flex-direction: column;
    }
    .page__topmeta {
      color: #97a3b5;
      font-size: 12px;
      text-align: right;
    }
    .page__number {
      position: absolute;
      right: 30px;
      bottom: 18px;
      color: #97a3b5;
      font-size: 12px;
    }
    .pdf-brand img {
      width: 190px;
      max-width: 100%;
      height: auto;
      display: block;
      margin-top: 52px;
    }
    .pdf-kicker {
      display: block;
      margin-top: 24px;
      color: #2a7bff;
      font-size: 14px;
      font-weight: 800;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }
    h1 {
      max-width: 470px;
      margin: 10px 0 0;
      font-size: 42px;
      line-height: 1.08;
      letter-spacing: -0.05em;
    }
    h2 {
      display: inline-block;
      margin: 0;
      padding-bottom: 6px;
      border-bottom: 3px solid #2a7bff;
      font-size: 28px;
    }
    h3 {
      margin: 0 0 12px;
      font-size: 18px;
    }
    .pdf-line {
      display: block;
      width: 80px;
      height: 4px;
      margin-top: 16px;
      border-radius: 999px;
      background: #2a7bff;
    }
    .pdf-cover-meta {
      margin-top: 28px;
      display: grid;
      gap: 16px;
      padding-bottom: 24px;
    }
    .pdf-cover-meta span,
    .pdf-cover-meta strong {
      display: block;
    }
    .pdf-cover-meta span {
      color: #8c97a8;
      font-size: 13px;
      font-weight: 700;
    }
    .pdf-cover-meta strong {
      margin-top: 4px;
      color: #1e2839;
      font-size: 16px;
    }
    .pdf-footer {
      margin-top: auto;
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      gap: 16px;
      padding-top: 14px;
      border-top: 1px solid #e3eaf4;
    }
    .pdf-footer strong,
    .pdf-footer span {
      display: block;
    }
    .pdf-footer div span {
      margin-top: 4px;
      color: #8793a7;
      font-size: 12px;
    }
    .pdf-footer > span {
      color: #97a3b5;
      font-size: 12px;
    }
    .pdf-index {
      margin-top: 18px;
    }
    .pdf-index-row {
      min-height: 34px;
      border-bottom: 1px solid #edf1f6;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      color: #344053;
      font-size: 14px;
    }
    .pdf-index-row strong {
      color: #8e98a9;
      font-weight: 400;
    }
    .pdf-note-card,
    .pdf-card,
    .pdf-signature-card {
      margin-top: 18px;
      padding: 16px 18px;
      border: 1px solid #e2eaf4;
      border-radius: 14px;
      background: #fbfdff;
    }
    .pdf-note-card strong,
    .pdf-note-card p {
      display: block;
    }
    .pdf-note-card p,
    .pdf-paragraph,
    .pdf-signature-card p {
      color: #546174;
      line-height: 1.6;
    }
    .pdf-paragraph {
      margin: 16px 0 0;
      max-width: 620px;
      font-size: 14px;
    }
    .pdf-metrics {
      margin-top: 20px;
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
    }
    .pdf-metric {
      padding: 14px 16px;
      border: 1px solid #e4eaf4;
      border-radius: 14px;
      background: #ffffff;
    }
    .pdf-metric span,
    .pdf-metric strong,
    .pdf-metric em {
      display: block;
    }
    .pdf-metric span {
      color: #8692a5;
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .pdf-metric strong {
      margin-top: 8px;
      color: #1d2739;
      font-size: 28px;
      line-height: 1;
    }
    .pdf-metric em {
      margin-top: 6px;
      color: #4f6278;
      font-size: 12px;
      font-style: normal;
      font-weight: 700;
    }
    .pdf-grid-2 {
      margin-top: 18px;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    .pdf-card--spaced {
      margin-top: 18px;
    }
    .pdf-distribution-list {
      display: grid;
      gap: 10px;
      margin-top: 12px;
    }
    .pdf-distribution-list div {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding-bottom: 10px;
      border-bottom: 1px solid #edf1f6;
      color: #415065;
    }
    .pdf-distribution-list div:last-child {
      padding-bottom: 0;
      border-bottom: 0;
    }
    .pdf-table {
      width: 100%;
      border-collapse: collapse;
    }
    .pdf-table th,
    .pdf-table td {
      padding: 10px 12px;
      border-bottom: 1px solid #e8eef6;
      text-align: left;
      vertical-align: top;
      font-size: 13px;
    }
    .pdf-table th {
      color: #6d7a8d;
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .pdf-empty,
    .pdf-empty-cell {
      color: #7a8798;
      text-align: center;
      padding: 14px;
    }
    .pdf-pill {
      display: inline-flex;
      align-items: center;
      min-height: 24px;
      padding: 0 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 800;
      white-space: nowrap;
    }
    .pdf-pill.is-low {
      background: #dcfce7;
      color: #15803d;
    }
    .pdf-pill.is-medium {
      background: #fef3c7;
      color: #b45309;
    }
    .pdf-pill.is-high {
      background: #fee2e2;
      color: #b91c1c;
    }
    .pdf-pill.is-neutral {
      background: #e2e8f0;
      color: #475569;
    }
    .pdf-list-card {
      display: grid;
      gap: 10px;
      margin-top: 10px;
    }
    .pdf-list-row {
      display: grid;
      grid-template-columns: 26px minmax(0, 1fr) auto;
      gap: 10px;
      align-items: center;
      padding: 12px;
      border: 1px solid #e5ebf4;
      border-radius: 12px;
      background: #ffffff;
    }
    .pdf-list-row__rank {
      width: 26px;
      height: 26px;
      border-radius: 999px;
      background: #eff4fb;
      color: #2e3a4d;
      display: grid;
      place-items: center;
      font-size: 13px;
      font-weight: 800;
    }
    .pdf-list-row__copy strong,
    .pdf-list-row__copy span,
    .pdf-list-row__meta strong {
      display: block;
    }
    .pdf-list-row__copy strong {
      color: #243044;
      font-size: 13px;
    }
    .pdf-list-row__copy span {
      margin-top: 4px;
      color: #8692a5;
      font-size: 12px;
    }
    .pdf-list-row__meta {
      display: grid;
      gap: 6px;
      justify-items: end;
    }
    .pdf-list-row__meta strong {
      color: #243044;
      font-size: 13px;
    }
    .pdf-heatmap-wrap {
      position: relative;
      width: fit-content;
      margin: 22px auto 0;
      padding: 0 0 36px 36px;
    }
    .pdf-heatmap-grid {
      position: relative;
      width: 290px;
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 2px;
      background: #ffffff;
    }
    .pdf-heatmap-cell {
      aspect-ratio: 1;
      display: grid;
      place-items: center;
      color: rgba(34, 48, 71, 0.68);
      font-size: 11px;
      font-weight: 800;
    }
    .pdf-g4 { background: #24ca5d; color: #ffffff; }
    .pdf-g3 { background: #4dd976; }
    .pdf-g2 { background: #77e39b; }
    .pdf-g1 { background: #a8ecc3; }
    .pdf-y1 { background: #fff2a3; }
    .pdf-y2 { background: #ffe76b; }
    .pdf-o1 { background: #ffa5a5; }
    .pdf-r1 { background: #f86d6d; color: #ffffff; }
    .pdf-r2 { background: #f44343; color: #ffffff; }
    .pdf-heatmap-axis {
      position: absolute;
      color: #7f8a9d;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.04em;
    }
    .pdf-heatmap-axis--left {
      top: 50%;
      left: 0;
      transform: translateY(-50%) rotate(-90deg);
      transform-origin: left top;
    }
    .pdf-heatmap-axis--bottom {
      right: 0;
      bottom: 0;
    }
    .pdf-heatmap-marker {
      position: absolute;
      width: 18px;
      height: 18px;
      border-radius: 999px;
      background: #1c2740;
      color: #ffffff;
      display: grid;
      place-items: center;
      font-size: 10px;
      font-weight: 800;
      transform: translate(50%, -50%);
    }
    .pdf-factor-list {
      display: grid;
      gap: 12px;
    }
    .pdf-factor-row {
      display: grid;
      grid-template-columns: 20px minmax(0, 1fr);
      gap: 10px;
      align-items: start;
    }
    .pdf-factor-row i {
      width: 20px;
      height: 20px;
      border-radius: 999px;
      background: #1c2740;
      color: #ffffff;
      display: grid;
      place-items: center;
      font-size: 11px;
      font-style: normal;
      font-weight: 800;
    }
    .pdf-factor-row strong,
    .pdf-factor-row span {
      display: block;
    }
    .pdf-factor-row strong {
      color: #293448;
      font-size: 13px;
    }
    .pdf-factor-row span {
      margin-top: 4px;
      color: #7a8698;
      font-size: 12px;
      line-height: 1.5;
    }
    .pdf-signature-card strong,
    .pdf-signature-card p {
      display: block;
    }
    .pdf-signatures {
      margin-top: 56px;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 24px;
    }
    .pdf-signatures span {
      display: block;
      height: 1px;
      margin-bottom: 8px;
      background: #c6d0de;
    }
    .pdf-signatures strong,
    .pdf-signatures small {
      display: block;
      text-align: center;
    }
    .pdf-signatures strong {
      font-size: 12px;
    }
    .pdf-signatures small {
      margin-top: 4px;
      color: #8793a7;
      font-size: 11px;
    }
    @page {
      size: A4 portrait;
      margin: 10mm;
    }
    @media print {
      body {
        padding: 0;
        background: #ffffff;
      }
      .page {
        width: auto;
        max-width: none;
        margin: 0 0 8mm;
        min-height: 0;
        padding: 28px 30px 34px;
        border: 0;
        border-radius: 0;
        box-shadow: none;
      }
      .page--cover {
        min-height: 257mm;
      }
      .page:last-child {
        margin-bottom: 0;
      }
    }
  </style>
</head>
<body>' . $pagesMarkup . '</body>
</html>';
}

$format = trim((string) ($_GET['format'] ?? 'excel'));
$sections = export_parse_sections($_GET);
$pdo = db();
$payload = reporting_build_payload($pdo, $_GET);
$companyName = code_segment((string) ($payload['company']['name'] ?? 'empresa'), 12, 'empresa');
$fileDate = date('Ymd_His');

if ($format === 'excel') {
    $worksheets = export_build_excel_rows($payload, $sections);
    $workbook = export_build_xlsx($worksheets);

    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="relatorio-' . strtolower($companyName) . '-' . $fileDate . '.xlsx"');
    header('Content-Length: ' . strlen($workbook));
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: public');

    echo $workbook;
    exit;
}

if ($format === 'pdf') {
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');

    echo export_pdf_render($payload, $sections);
    exit;
}

send_json(422, [
    'success' => false,
    'message' => 'Formato de exportacao invalido.',
]);
