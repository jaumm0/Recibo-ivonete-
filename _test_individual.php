<?php
$post = http_build_query([
    'acao'        => 'gerar',
    'formato'     => 'docx',
    'empresa'     => 'Laboratório',
    'nome'        => 'Luciano Akira Kamada',
    'cpf'         => '',
    'combustivel' => '0',
    'alimentacao' => '0',
    'aj_custo'    => '0',
    'periodo'     => '01/08/2026 a 31/08/2026',
    'data'        => '2026-08-31',
    'cidade'      => 'Campo Grande',
]);

$ctx = stream_context_create(['http' => [
    'method'  => 'POST',
    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content' => $post,
    'ignore_errors' => true,
]]);

$res = @file_get_contents('http://127.0.0.1:8000/gerar.php', false, $ctx);
$headers = $http_response_header ?? [];
$ctype = '?';
foreach ($headers as $h) {
    if (stripos($h, 'content-type:') === 0) $ctype = trim(substr($h, 13));
}
$len = strlen($res);
echo "Content-Type: $ctype | Tamanho: $len bytes\n";

if (strpos($ctype, 'html') !== false) {
    if (strpos($res, 'Recibo não gerado') !== false) {
        echo ">>> BLOQUEADO corretamente com mensagem de erro.\n";
    } else {
        echo "HTML diferente do esperado:\n" . substr($res, 0, 500) . "\n";
    }
} else {
    echo ">>> BUG: saiu arquivo de {$len} bytes (Content-Type: $ctype)\n";
    file_put_contents(__DIR__ . '/_teste_vazou.bin', $res);
}