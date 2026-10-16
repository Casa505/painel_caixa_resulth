<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function brMoney($value): string
{
    return number_format((float)$value, 2, ',', '.');
}

function validarData(string $data): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $data);
    return $d !== false && $d->format('Y-m-d') === $data;
}

function fbInicio(string $data): string
{
    $d = DateTime::createFromFormat('Y-m-d', $data);
    return $d->format('m/d/Y') . ' 00:00:00';
}

function fbFimExclusivo(string $data): string
{
    $d = DateTime::createFromFormat('Y-m-d', $data);
    $d->modify('+1 day');
    return $d->format('m/d/Y') . ' 00:00:00';
}

function adicionarFiltroCliente(string &$sql, array &$params, string $aliasPedidoOuMov, string $aliasCliente, string $cliente): void
{
    if ($cliente === '') {
        return;
    }

    $sql .= "\n  AND (" . $aliasPedidoOuMov . ".CODCLIENTE CONTAINING ? OR " . $aliasCliente . ".NOME CONTAINING ?)";
    $params[] = $cliente;
    $params[] = $cliente;
}

$hoje = date('Y-m-d');
$dataIni = $_GET['data_ini'] ?? $hoje;
$dataFim = $_GET['data_fim'] ?? $hoje;
$empresa = trim((string)($_GET['empresa'] ?? $config['empresa']));
$usuario = trim((string)($_GET['usuario'] ?? ''));
$tipo = strtoupper(trim((string)($_GET['tipo'] ?? '')));
$cliente = trim((string)($_GET['cliente'] ?? ''));
$formaPagamento = strtoupper(trim((string)($_GET['forma_pagamento'] ?? 'TODOS')));

if (!in_array($tipo, ['', 'PV', 'RC'], true)) {
    $tipo = '';
}

$formasPagamento = [
    'TODOS' => 'Todos',
    'DINHEIRO' => 'Dinheiro',
    'CARTAO' => 'Cartão',
    'RECEBER' => 'Receber',
    'CHEQUE' => 'Cheque',
    'CREDITO' => 'Crédito',
    'PIX' => 'PIX',
    'BANCO' => 'Banco',
];

if (!array_key_exists($formaPagamento, $formasPagamento)) {
    $formaPagamento = 'TODOS';
}

$erro = null;
$rows = [];
$usuariosAtivos = [];
$usuarioDescricao = 'Todos os usuários';
$totais = [
    'TOTAL' => 0.0,
    'DINHEIRO' => 0.0,
    'CARTAO' => 0.0,
    'RECEBER' => 0.0,
    'CHEQUE' => 0.0,
    'CREDITO' => 0.0,
    'PIX' => 0.0,
    'BANCO' => 0.0,
];

if (!validarData($dataIni) || !validarData($dataFim)) {
    $erro = 'Informe datas válidas.';
} elseif ($dataIni > $dataFim) {
    $erro = 'A data inicial não pode ser maior que a data final.';
}

if ($erro === null) {
    try {
        if (!in_array('firebird', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException(
                'O driver PDO Firebird não está habilitado no PHP. Habilite a extensão pdo_firebird no php.ini e reinicie o Apache.'
            );
        }

        $dsn = 'firebird:dbname=' . $config['database'] . ';charset=' . $config['charset'];

        $pdo = new PDO(
            $dsn,
            $config['user'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $stmtUsuarios = $pdo->query(
            "SELECT DISTINCT USUARIO, NOME FROM OPERADOR WHERE ATIVO = 'S' ORDER BY NOME, USUARIO"
        );
        $usuariosAtivos = $stmtUsuarios->fetchAll();

        if ($usuario !== '') {
            $usuariosValidos = array_column($usuariosAtivos, 'USUARIO');
            if (!in_array($usuario, $usuariosValidos, true)) {
                $usuario = '';
            }
        }

        if ($usuario !== '') {
            foreach ($usuariosAtivos as $op) {
                if ((string)$op['USUARIO'] === $usuario) {
                    $usuarioDescricao = (string)$op['USUARIO'] . ' - ' . (string)$op['NOME'];
                    break;
                }
            }
        }

        $inicioFb = fbInicio($dataIni);
        $fimFb = fbFimExclusivo($dataFim);

        $partes = [];
        $params = [];

        // ============================
        // VENDAS - PEDIDOC
        // ============================
        if ($tipo !== 'RC') {
            $sqlPV = <<<SQL
SELECT
    P.DATAFATURA AS DATA,
    'PV' AS TIPO,
    P.CODPEDIDO AS CODIGO,
    P.CODCLIENTE,
    C.NOME AS CLIENTE,
    P.USUARIO,
    P.TOTALPEDIDO AS TOTAL,

(
    COALESCE((
        SELECT SUM(D.VALOR)
        FROM DAVPV_DINHEIRO D
        WHERE D.ID_DAVPV = P.ID_DAVPV
          AND D.CODEMPRESA = P.CODEMPRESA
    ), 0)
    -
    COALESCE(E.VLRTROCO, 0)
) AS DINHEIRO,

    COALESCE((
        SELECT SUM(CA.valor)
        FROM davpv_convenio CA
        WHERE CA.ID_DAVPV = P.ID_DAVPV
          AND CA.CODEMPRESA = P.CODEMPRESA
    ), 0) AS CARTAO,

    COALESCE((
        SELECT SUM(R.VALOR)
        FROM DAVPV_RECEBER R
        WHERE R.ID_DAVPV = P.ID_DAVPV
          AND R.CODEMPRESA = P.CODEMPRESA
    ), 0) AS RECEBER,

    COALESCE((
        SELECT SUM(CH.VLRCHEQUE)
        FROM DAVPV_CHEQUE CH
        WHERE CH.ID_DAVPV = P.ID_DAVPV
          AND CH.CODEMPRESA = P.CODEMPRESA
    ), 0) AS CHEQUE,

    COALESCE((
        SELECT SUM(CR.VALORRESERVADO)
        FROM DAVPV_CREDITO CR
        WHERE CR.ID_DAVPV = P.ID_DAVPV
          AND CR.CODEMPRESA = P.CODEMPRESA
    ), 0) AS CREDITO,

    COALESCE((
        SELECT SUM(PD.VALOR)
        FROM PAGAMENTODIGITAL PD
        WHERE PD.CODEMPRESA = P.CODEMPRESA
          AND PD.TIPOPEDIDO = P.TIPOPEDIDO
          AND PD.CODPEDIDO = P.CODPEDIDO
    ), 0) AS PIX,

    COALESCE((
        SELECT SUM(B.VALOR)
        FROM DAVPV_DEPOSITO B
        WHERE B.ID_DAVPV = P.ID_DAVPV
          AND B.CODEMPRESA = P.CODEMPRESA
    ), 0) AS BANCO

FROM PEDIDOC P
LEFT JOIN CLIENTE C
       ON C.CODCLIENTE = P.CODCLIENTE
LEFT JOIN ENCEFAT E
       ON E.CODEMPRESA = P.CODEMPRESA
      AND E.TIPOPEDIDO = P.TIPOPEDIDO
      AND E.CODPEDIDO = P.CODPEDIDO


WHERE P.CODEMPRESA = ?
  AND P.TIPOPEDIDO = '55'
  AND P.FATURADO = 'S'
  AND P.DATAFATURA >= ?
  AND P.DATAFATURA < ?
SQL;

            $paramsPV = [$empresa, $inicioFb, $fimFb];

            if ($usuario !== '') {
                $sqlPV .= "\n  AND P.USUARIO = ?";
                $paramsPV[] = $usuario;
            }

            adicionarFiltroCliente($sqlPV, $paramsPV, 'P', 'C', $cliente);

            $partes[] = $sqlPV;
            array_push($params, ...$paramsPV);
        }

        // ============================
        // RECEBIMENTOS - ENCELIQ
        // Valores por forma: ENCELIQ (uma linha por liquidacao)
        // Usuario: MOVIREC vinculada por NUMORD, sem somar seus movimentos
        // ============================
        if ($tipo !== 'PV') {
            $sqlRC = <<<SQL
SELECT
    E.DATALIQ AS DATA,
    'RC' AS TIPO,
    CAST(E.SEQUENCIA AS VARCHAR(20)) AS CODIGO,
    E.CODPESSOA AS CODCLIENTE,
    C.NOME AS CLIENTE,

    COALESCE(
        (
            SELECT FIRST 1 M.USUARIO
            FROM MOVIREC M
            WHERE M.CODEMPRESA = E.CODEMPRESA
              AND M.CODCLIENTE = E.CODPESSOA
              AND M.NUMORD = E.NUMORD
              AND (M.ESTORNADO IS NULL OR M.ESTORNADO = 'N')
            ORDER BY M.DT_MOVIMENTO DESC, M.NUMORD DESC
        ),
        (
            SELECT FIRST 1 M.USUARIO
            FROM MOVIREC M
            WHERE M.CODEMPRESA = E.CODEMPRESA
              AND M.CODCLIENTE = E.CODPESSOA
              AND M.ORDEMLIQRECEBER = E.DOCUMENTO
              AND M.DT_MOVIMENTO >= E.DATALIQ
              AND M.DT_MOVIMENTO < E.DATALIQ + 1
              AND (M.ESTORNADO IS NULL OR M.ESTORNADO = 'N')
            ORDER BY M.DT_MOVIMENTO DESC, M.NUMORD DESC
        )
    ) AS USUARIO,

    (
        CASE
            WHEN COALESCE(E.VLRDINHEIRO, 0) > 0
             AND COALESCE(E.VLRCARTAO, 0) = 0
             AND COALESCE(E.VLRCHEQUE, 0) = 0
             AND COALESCE(E.VLRDEPOSITO, 0) = 0
             AND COALESCE(E.VLRCREDITO, 0) = 0
             AND COALESCE(E.VLRCONVENIO, 0) = 0
             AND COALESCE(E.VLRCARTDIGITAL, 0) = 0
             AND L.TOTAL_LIQUIDADO IS NOT NULL
             AND L.TOTAL_LIQUIDADO BETWEEN 0
                 AND COALESCE(E.VLRDINHEIRO, 0)
            THEN L.TOTAL_LIQUIDADO
            ELSE COALESCE(E.VLRDINHEIRO, 0)
                 - COALESCE(E.VLRTROCO, 0)
        END
        + COALESCE(E.VLRCARTAO, 0)
        + COALESCE(E.VLRCONVENIO, 0)
        + COALESCE(E.VLRCHEQUE, 0)
        + COALESCE(E.VLRCREDITO, 0)
        + COALESCE(E.VLRCARTDIGITAL, 0)
        + COALESCE(E.VLRDEPOSITO, 0)
    ) AS TOTAL,

    CASE
        WHEN COALESCE(E.VLRDINHEIRO, 0) > 0
         AND COALESCE(E.VLRCARTAO, 0) = 0
         AND COALESCE(E.VLRCHEQUE, 0) = 0
         AND COALESCE(E.VLRDEPOSITO, 0) = 0
         AND COALESCE(E.VLRCREDITO, 0) = 0
         AND COALESCE(E.VLRCONVENIO, 0) = 0
         AND COALESCE(E.VLRCARTDIGITAL, 0) = 0
         AND L.TOTAL_LIQUIDADO IS NOT NULL
         AND L.TOTAL_LIQUIDADO BETWEEN 0
             AND COALESCE(E.VLRDINHEIRO, 0)
        THEN L.TOTAL_LIQUIDADO
        ELSE COALESCE(E.VLRDINHEIRO, 0)
             - COALESCE(E.VLRTROCO, 0)
    END AS DINHEIRO,

    COALESCE(E.VLRCARTAO, 0)
      + COALESCE(E.VLRCONVENIO, 0) AS CARTAO,

    CAST(0 AS DOUBLE PRECISION) AS RECEBER,

    COALESCE(E.VLRCHEQUE, 0) AS CHEQUE,

    COALESCE(E.VLRCREDITO, 0) AS CREDITO,

    COALESCE(E.VLRCARTDIGITAL, 0) AS PIX,

    COALESCE(E.VLRDEPOSITO, 0) AS BANCO

FROM ENCELIQ E

LEFT JOIN CLIENTE C
    ON C.CODCLIENTE = E.CODPESSOA

LEFT JOIN (
    SELECT
        M.CODEMPRESA,
        M.CODCLIENTE,
        M.ORDEMLIQRECEBER,
        SUM(COALESCE(M.VALORMOV, 0)) AS TOTAL_LIQUIDADO
    FROM MOVIREC M
    WHERE M.TIPOMOV IN ('04', '05')
      AND (M.ESTORNADO IS NULL OR M.ESTORNADO = 'N')
    GROUP BY
        M.CODEMPRESA,
        M.CODCLIENTE,
        M.ORDEMLIQRECEBER
) L
    ON L.CODEMPRESA = E.CODEMPRESA
   AND L.CODCLIENTE = E.CODPESSOA
   AND L.ORDEMLIQRECEBER = E.DOCUMENTO

WHERE E.CODEMPRESA = ?
  AND TRIM(E.ORIGEM) = 'Receber'
  AND E.DATALIQ >= ?
  AND E.DATALIQ < ?
SQL;

            $paramsRC = [$empresa, $inicioFb, $fimFb];

            if ($usuario !== '') {
                // Mesmo filtro de usuario do seletor original (OPERADOR.USUARIO).
                $sqlRC .= "\n  AND EXISTS (SELECT 1 FROM MOVIREC MU
                    WHERE MU.CODEMPRESA = E.CODEMPRESA
                      AND MU.NUMORD = E.NUMORD
                      AND MU.CODCLIENTE = E.CODPESSOA
                      AND MU.USUARIO = ?
                      AND (MU.ESTORNADO IS NULL OR MU.ESTORNADO = 'N'))";
                $paramsRC[] = $usuario;
            }

            if ($cliente !== '') {
                $sqlRC .= "\n  AND (E.CODPESSOA CONTAINING ? OR C.NOME CONTAINING ?)";
                $paramsRC[] = $cliente;
                $paramsRC[] = $cliente;
            }

            $partes[] = $sqlRC;
            array_push($params, ...$paramsRC);
        }

        if (!$partes) {
            throw new RuntimeException('Nenhuma origem selecionada para consulta.');
        }

        $sqlUnion = implode("\nUNION ALL\n", $partes);

        if ($formaPagamento !== 'TODOS') {
            $sqlFinal = "SELECT * FROM (\n" . $sqlUnion . "\n) X\n"
                . "WHERE X." . $formaPagamento . " > 0\n"
                . "ORDER BY 1, 2, 3";
        } else {
            $sqlFinal = $sqlUnion . "\nORDER BY 1, 2, 3";
        }

        $stmt = $pdo->prepare($sqlFinal);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            foreach ($totais as $campo => $valor) {
                $totais[$campo] += (float)$row[$campo];
            }
        }

        // Exportação CSV do mesmo resultado filtrado.
        if (($_GET['export'] ?? '') === 'csv') {
            $nome = 'caixa_' . $dataIni . '_a_' . $dataFim . '.csv';
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $nome . '"');
            echo "\xEF\xBB\xBF";

            $out = fopen('php://output', 'w');
            fputcsv(
                $out,
                ['DATA', 'TIPO', 'CODIGO', 'CODCLIENTE', 'CLIENTE', 'USUARIO', 'TOTAL', 'DINHEIRO', 'CARTAO', 'RECEBER', 'CHEQUE', 'CREDITO', 'PIX', 'BANCO'],
                ';'
            );

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['DATA'],
                    $row['TIPO'],
                    $row['CODIGO'],
                    $row['CODCLIENTE'],
                    $row['CLIENTE'],
                    $row['USUARIO'],
                    number_format((float)$row['TOTAL'], 2, ',', ''),
                    number_format((float)$row['DINHEIRO'], 2, ',', ''),
                    number_format((float)$row['CARTAO'], 2, ',', ''),
                    number_format((float)$row['RECEBER'], 2, ',', ''),
                    number_format((float)$row['CHEQUE'], 2, ',', ''),
                    number_format((float)$row['CREDITO'], 2, ',', ''),
                    number_format((float)$row['PIX'], 2, ',', ''),
                    number_format((float)$row['BANCO'], 2, ',', ''),
                ], ';');
            }

            fclose($out);
            exit;
        }

    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$queryExport = $_GET;
$queryExport['export'] = 'csv';
$urlExport = '?' . http_build_query($queryExport);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Caixa - Resulth</title>
    <style>
        :root {
            --bg: #f3f5f7;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --line: #d9dee5;
            --primary: #b51f1f;
            --primary-dark: #8e1717;
            --pv: #e8f4ff;
            --rc: #edf9ee;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .container {
            width: min(1600px, 98%);
            margin: 18px auto;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
        }

        .header small { color: var(--muted); }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,.04);
            padding: 16px;
            margin-bottom: 14px;
        }

        .filters {
            display: grid;
            grid-template-columns: repeat(8, minmax(135px, 1fr));
            gap: 12px;
            align-items: end;
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: bold;
            color: #4b5563;
            margin-bottom: 5px;
        }

        input, select, button, .btn {
            width: 100%;
            min-height: 38px;
            border-radius: 6px;
            border: 1px solid #cbd2da;
            padding: 8px 10px;
            font-size: 14px;
            background: #fff;
        }

        button, .btn {
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .btn-primary:hover { background: var(--primary-dark); }
        .btn-secondary { background: #fff; color: #374151; }

        .actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .totals {
            display: grid;
            grid-template-columns: repeat(8, minmax(130px, 1fr));
            gap: 10px;
        }

        .total-box {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 10px;
            background: #fafafa;
        }

        .total-box .label {
            font-size: 11px;
            color: var(--muted);
            font-weight: bold;
        }

        .total-box .value {
            margin-top: 4px;
            font-size: 18px;
            font-weight: bold;
        }

        .table-wrap {
            overflow: auto;
            max-height: 68vh;
            border: 1px solid var(--line);
            border-radius: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1350px;
            font-size: 13px;
        }

        th, td {
            border-bottom: 1px solid #e5e7eb;
            padding: 8px 9px;
            white-space: nowrap;
        }

        th {
            position: sticky;
            top: 0;
            background: #f8fafc;
            z-index: 2;
            text-align: left;
            font-size: 12px;
        }

        td.num, th.num { text-align: right; }
        tr.pv { background: var(--pv); }
        tr.rc { background: var(--rc); }
        tr:hover { filter: brightness(.98); }

        .badge {
            display: inline-block;
            border-radius: 999px;
            padding: 3px 8px;
            font-size: 11px;
            font-weight: bold;
            background: #e5e7eb;
        }

        .error {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            color: #9f1239;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 14px;
            white-space: pre-wrap;
        }

        .info {
            color: var(--muted);
            font-size: 12px;
            margin-top: 10px;
        }

        .count {
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 8px;
        }

        @media (max-width: 1100px) {
            .filters { grid-template-columns: repeat(3, 1fr); }
            .totals { grid-template-columns: repeat(4, 1fr); }
        }

        .print-header {
            display: none;
        }

        @media (max-width: 650px) {
            .filters { grid-template-columns: 1fr 1fr; }
            .totals { grid-template-columns: 1fr 1fr; }
            .header { align-items: flex-start; flex-direction: column; }
        }

        @media print {
            @page {
                size: landscape;
                margin: 8mm;
            }

            body {
                background: #fff;
                color: #000;
                font-size: 10px;
            }

            .container {
                width: 100%;
                margin: 0;
            }

            .no-print,
            .info,
            .error {
                display: none !important;
            }

            .header {
                margin-bottom: 6px;
            }

            .header h1 {
                font-size: 18px;
            }

            .header small {
                color: #000;
            }

            .print-header {
                display: block;
                border: 1px solid #999;
                padding: 7px 9px;
                margin-bottom: 8px;
                font-size: 10px;
                line-height: 1.45;
            }

            .card {
                border: 0;
                box-shadow: none;
                border-radius: 0;
                padding: 0;
                margin-bottom: 8px;
            }

            .totals {
                display: grid;
                grid-template-columns: repeat(8, 1fr);
                gap: 4px;
            }

            .total-box {
                border: 1px solid #999;
                border-radius: 0;
                padding: 5px;
                background: #fff;
            }

            .total-box .label {
                color: #000;
                font-size: 8px;
            }

            .total-box .value {
                font-size: 10px;
                margin-top: 2px;
            }

            .count {
                color: #000;
                font-size: 9px;
                margin-bottom: 4px;
            }

            .table-wrap {
                overflow: visible;
                max-height: none;
                border: 0;
                border-radius: 0;
            }

            table {
                min-width: 0;
                width: 100%;
                font-size: 8px;
            }

            th, td {
                border: 1px solid #999;
                padding: 3px 4px;
                white-space: nowrap;
            }

            th {
                position: static;
                background: #eee !important;
                color: #000;
                font-size: 8px;
            }

            tr.pv,
            tr.rc {
                background: #fff !important;
            }

            tr:hover {
                filter: none;
            }

            .badge {
                background: transparent;
                padding: 0;
                border-radius: 0;
                font-size: 8px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>Consulta de Caixa</h1>
            <small>Vendas faturadas + recebimentos</small>
        </div>
    </div>

    <div class="card no-print">
        <form method="get" class="filters">
            <div>
                <label for="data_ini">Data inicial</label>
                <input type="date" id="data_ini" name="data_ini" value="<?= h($dataIni) ?>" required>
            </div>

            <div>
                <label for="data_fim">Data final</label>
                <input type="date" id="data_fim" name="data_fim" value="<?= h($dataFim) ?>" required>
            </div>

            <div>
                <label for="empresa">Empresa</label>
                <input type="text" id="empresa" name="empresa" value="<?= h($empresa) ?>" maxlength="2">
            </div>

            <div>
                <label for="usuario">Usuário ativo</label>
                <select id="usuario" name="usuario">
                    <option value="">Todos os usuários</option>
                    <?php foreach ($usuariosAtivos as $op): ?>
                        <option value="<?= h((string)$op['USUARIO']) ?>" <?= $usuario === (string)$op['USUARIO'] ? 'selected' : '' ?>>
                            <?= h((string)$op['USUARIO']) ?> - <?= h((string)$op['NOME']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="tipo">Tipo</label>
                <select id="tipo" name="tipo">
                    <option value="" <?= $tipo === '' ? 'selected' : '' ?>>Todos</option>
                    <option value="PV" <?= $tipo === 'PV' ? 'selected' : '' ?>>PV - Vendas</option>
                    <option value="RC" <?= $tipo === 'RC' ? 'selected' : '' ?>>RC - Recebimentos</option>
                </select>
            </div>

            <div>
                <label for="forma_pagamento">Forma de pagamento</label>
                <select id="forma_pagamento" name="forma_pagamento">
                    <?php foreach ($formasPagamento as $valorForma => $descricaoForma): ?>
                        <option value="<?= h($valorForma) ?>" <?= $formaPagamento === $valorForma ? 'selected' : '' ?>>
                            <?= h($descricaoForma) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="cliente">Cliente</label>
                <input type="text" id="cliente" name="cliente" value="<?= h($cliente) ?>" placeholder="Código ou nome">
            </div>

            <div class="actions">
                <button type="submit" class="btn-primary">Consultar</button>
                <a class="btn btn-secondary" href="index.php">Limpar</a>
            </div>

            <?php if ($erro === null): ?>
                <div>
                    <label>&nbsp;</label>
                    <a class="btn btn-secondary" href="<?= h($urlExport) ?>">Exportar CSV</a>
                </div>

                <div>
                    <label>&nbsp;</label>
                    <button type="button" class="btn btn-secondary" onclick="window.print()">Imprimir resultado</button>
                </div>
            <?php endif; ?>
        </form>

        <div class="info">
            Usuários: somente operadores ativos. Forma de pagamento: mostra apenas registros com valor maior que zero na opção escolhida.
            No recebimento, a forma 06 (PIX) é apresentada em BANCO; nas vendas, PIX permanece na coluna PIX.
        </div>
    </div>

    <?php if ($erro === null): ?>
        <div class="print-header">
            <strong>Filtros da consulta</strong><br>
            Período: <?= h(date('d/m/Y', strtotime($dataIni))) ?> a <?= h(date('d/m/Y', strtotime($dataFim))) ?>
            &nbsp;|&nbsp; Empresa: <?= h($empresa) ?>
            &nbsp;|&nbsp; Usuário: <?= h($usuarioDescricao) ?><br>
            Tipo: <?= h($tipo === '' ? 'Todos' : $tipo) ?>
            &nbsp;|&nbsp; Forma de pagamento: <?= h($formasPagamento[$formaPagamento]) ?>
            &nbsp;|&nbsp; Cliente: <?= h($cliente === '' ? 'Todos' : $cliente) ?>
        </div>
    <?php endif; ?>

    <?php if ($erro !== null): ?>
        <div class="error"><?= h($erro) ?></div>
    <?php else: ?>
        <div class="card">
            <div class="totals">
                <?php foreach ($totais as $nome => $valor): ?>
                    <div class="total-box">
                        <div class="label"><?= h($nome) ?></div>
                        <div class="value">R$ <?= brMoney($valor) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="count"><?= count($rows) ?> registro(s) encontrado(s)</div>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>DATA</th>
                        <th>TIPO</th>
                        <th>CÓDIGO</th>
                        <th>COD. CLIENTE</th>
                        <th>CLIENTE</th>
                        <th>USUÁRIO</th>
                        <th class="num">TOTAL</th>
                        <th class="num">DINHEIRO</th>
                        <th class="num">CARTÃO</th>
                        <th class="num">RECEBER</th>
                        <th class="num">CHEQUE</th>
                        <th class="num">CRÉDITO</th>
                        <th class="num">PIX</th>
                        <th class="num">BANCO</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="14">Nenhum registro encontrado para os filtros informados.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr class="<?= strtolower(h($row['TIPO'])) ?>">
                                <td><?= h((string)$row['DATA']) ?></td>
                                <td><span class="badge"><?= h($row['TIPO']) ?></span></td>
                                <td><?= h((string)$row['CODIGO']) ?></td>
                                <td><?= h((string)$row['CODCLIENTE']) ?></td>
                                <td><?= h((string)$row['CLIENTE']) ?></td>
                                <td><?= h((string)$row['USUARIO']) ?></td>
                                <td class="num"><?= brMoney($row['TOTAL']) ?></td>
                                <td class="num"><?= brMoney($row['DINHEIRO']) ?></td>
                                <td class="num"><?= brMoney($row['CARTAO']) ?></td>
                                <td class="num"><?= brMoney($row['RECEBER']) ?></td>
                                <td class="num"><?= brMoney($row['CHEQUE']) ?></td>
                                <td class="num"><?= brMoney($row['CREDITO']) ?></td>
                                <td class="num"><?= brMoney($row['PIX']) ?></td>
                                <td class="num"><?= brMoney($row['BANCO']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
