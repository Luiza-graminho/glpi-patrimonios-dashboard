<?php

// Chamar arquivo com as variáveis de configuração
require_once 'config.php';

// Conexão com a API do GLPI
session_start();

$curl = curl_init();
$url_init_session = $glpi_url . "/initSession";

curl_setopt_array($curl, array(
    CURLOPT_URL => $url_init_session,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_HTTPHEADER => array(
        'Authorization: ' . $authorization
    ),
));

$response = curl_exec($curl);
if (!$response) {
    echo ("Erro ao iniciar sessão: " . curl_error($curl));
}
curl_close($curl);

$data = json_decode($response, true);

// Adiciona o token a variável $session_token

if (isset($data['session_token'])) {
    $session_token['glpi_session_token'] = $data['session_token'];
    error_log("Sessão iniciada com sucesso");
} else {
    error_log("Erro ao iniciar sessão: " . $response);
}

// Adiciona o Session Token ao Header para conexão com a API
function glpi_consult($endpoint, $session_token)
{
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_HTTPHEADER => array(
            'Session-Token: ' . $session_token['glpi_session_token']
        ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}

// Url para acesso aos Ativos > Computadores
$url_computer = $glpi_url . "/search/Computer?range=0-1000&expand_dropdowns=true";

// Url para acesso ao Plugin de Campos Adicionais > Autorização para Saída
$url_plugin = $glpi_url . "/PluginFieldsComputerautorizaoparasada?range=0-1000&expand_dropdowns=true";

// Url + Session Token para consulta dos dados
$computers = glpi_consult($url_computer, $session_token);
$computers_plugin = glpi_consult($url_plugin, $session_token);

/*
echo "<pre>";
print_r($computers['data']);
echo "</pre>";
*/

// Inicia Array que irá armazenar os dados
$result_final = [];

// Inicia array de índice
$plugin_index = [];

// Para cada computador do plugin, utilize o items_id como indíce
if (!empty($computers_plugin)) {
    foreach ($computers_plugin as $plugin) {
        $plugin_index[$plugin['items_id']] = $plugin;
    }
}

// Para cada computador em Ativos > Computadores filtre os dados requeridos
foreach ($computers['data'] as $computer) {

    $patrimonio = $computer[6];
    $usuario = $computer[70] ?? '---';
    $name = $computer[1];

    //Compara o índice com o "name" de cada item
    $plugin_item = $plugin_index[$name] ?? null;

    $status_id = $plugin_item['plugin_fields_statusdesadafielddropdowns_id'] ?? null;

    if ($status_id == 1) {
        $status_name = "Não Autorizado";
    } elseif ($status_id == 2) {
        $status_name = "Autorizado";
    } else {
        $status_name = "---";
    }

    $validade = $plugin_item['datadevalidadefield'] ?? null;
    $validade_form = $validade ? date('d/m/y', strtotime($validade)) : '---';

    $aprovacao = $plugin_item['datadeaprovaofield'] ?? null;
    $aprovacao_form = $aprovacao ? date('d/m/y', strtotime($aprovacao)) : '---';

    if (!empty($patrimonio)) {
        $result_final[] = [
            'Patrimônio' => $patrimonio,
            'Usuário' => $usuario,
            'Status de Saída' => $status_name,
            'Data de Aprovação' => $aprovacao_form,
            'Validade' => $validade_form,
            'Aprovador' => isset($plugin_item['users_id_aprovadorfield']) && $plugin_item['users_id_aprovadorfield']
                ? $plugin_item['users_id_aprovadorfield']
                : '---',
        ];
    }
}

// Lê os parâmetros da URL
$por_pagina = 50;
$pagina = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;
$busca = $_GET['busca'] ?? '';
$ordenar = $_GET['ordenar'] ?? 'Status de Saída';
$direcao = $_GET['direcao'] ?? 'asc';

// Transforma os dados do array em string e faz a busca do texto digitado
if (!empty($busca)) {
    $result_final = array_filter($result_final, function ($item) use ($busca) {
        return stripos(implode(' ', $item), $busca) !== false;
    });
}

// Compara todos os registros para ordenação
usort($result_final, function ($a, $b) use ($ordenar, $direcao) {
    $valorA = $a[$ordenar] ?? '';
    $valorB = $b[$ordenar] ?? '';

    if ($direcao === 'asc') {
        return $valorA <=> $valorB;
    } else {
        return $valorB <=> $valorA;
    }
});

// Verifica o número total de registros
$total_registros = count($result_final);

// Calcula o número de páginas necessárias e faz o arredondamento do valor
$total_paginas = ceil($total_registros / $por_pagina);

// Verifica em qual página começar e limita o número de registros visíveis
$inicio = ($pagina - 1) * $por_pagina;
$dados_pagina = array_slice($result_final, $inicio, $por_pagina);

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Equipamentos</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen">

    <!-- Header -->
    <header class="bg-green-700 w-full flex justify-end items-center h-10 sm:h-16 px-4 sm:px-8">
        <img alt="logo" src="logo_saur_b.svg" class="h-4 sm:h-10 object-contain">
    </header>

    <main class="w-full px-6 py-8 flex justify-center">
        <div class="max-w-7xl mx-auto bg-white shadow-xl rounded-2xl p-6">
            <div class="flex justify-between items-center mb-4">
                <!-- Título -->
                <h1 class="text-lg font-semibold text-gray-700">Lista de Equipamentos</h1>

                <!-- Campo de Busca -->
                <form method="get" class="relative w-64">
                    <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Buscar..."
                        class="w-full border border-gray-300 rounded-xl pl-10 pr-4 py-2 text-sm
                        focus:ring-2 focus:ring-green-500 focus:border-green-500
                        transition shadow-sm">

                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 21l-4.35-4.35M16 10a6 6 0 11-12 0 6 6 0 0112 0z" />
                        </svg>
                    </div>
                </form>

            </div>

            <div class="w-full overflow-x-auto rounded-xl border">
                <!-- Criação da Tabela -->
                <div class="max-h-[600px] overflow-y-auto">
                    <table class="min-w-full text-sm sm:text-base text-gray-700">
                        <thead class="bg-gray-200 text-gray-700 sticky top-0 z-10">
                            <tr>
                                <?php
                                // Definição das Colunas da Tabela
                                $colunas = ['Patrimônio', 'Usuário', 'Status de Saída', 'Data de Aprovação', 'Validade', 'Aprovador'];

                                // Para cada coluna criar uma tag th
                                foreach ($colunas as $coluna):

                                    // Alterna a direação de ordenação conforme clique
                                    $nova_direcao = ($ordenar === $coluna && $direcao === 'asc') ? 'desc' : 'asc';
                                    ?>
                                    <th class="px-6 py-4 text-center font-semibold whitespace-nowrap">
                                        <?php
                                        $coluna_ativa = ($ordenar === $coluna);
                                        $nova_direcao = ($coluna_ativa && $direcao === 'asc') ? 'desc' : 'asc';
                                        ?>

                                        <a href="?ordenar=<?= urlencode($coluna) ?>&direcao=<?= $nova_direcao ?>&busca=<?= urlencode($busca) ?>&pagina=1"
                                            class="inline-flex items-center gap-2 group transition
                                                <?= $coluna_ativa ? 'font-bold' : 'text-gray-700 hover:text-green-600' ?>">

                                            <?= $coluna ?>

                                            <!-- Ícone -->
                                            <span
                                                class="transition transform
                                                <?= $coluna_ativa ? 'text-green-600' : 'text-gray-300 group-hover:text-green-500' ?>">

                                                <?php if ($coluna_ativa): ?>

                                                    <?php if ($direcao === 'asc'): ?>
                                                        <!-- Seta para cima -->
                                                        <svg xmlns="http://www.w3.org/2000/svg"
                                                            class="h-4 w-4 transition-transform duration-300" fill="none"
                                                            viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                d="M5 15l7-7 7 7" />
                                                        </svg>
                                                    <?php else: ?>
                                                        <!-- Seta para baixo -->
                                                        <svg xmlns="http://www.w3.org/2000/svg"
                                                            class="h-4 w-4 transition-transform duration-300" fill="none"
                                                            viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                d="M19 9l-7 7-7-7" />
                                                        </svg>
                                                    <?php endif; ?>

                                                <?php else: ?>
                                                    <!-- Ícone neutro -->
                                                    <svg xmlns="http://www.w3.org/2000/svg"
                                                        class="h-4 w-4 opacity-40 group-hover:opacity-100 transition"
                                                        fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                            d="M8 9l4-4 4 4M16 15l-4 4-4-4" />
                                                    </svg>
                                                <?php endif; ?>

                                            </span>
                                        </a>
                                    </th>

                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">

                            <!-- Percorre os dados da página atual -->
                            <?php foreach ($dados_pagina as $item):

                                // Estilização conforme Status
                                $status = $item['Status de Saída'] ?? '';

                                $status_class = ($status === 'Autorizado')
                                    ? 'bg-green-200 text-green-800'
                                    : 'bg-red-200 text-red-800';
                                ?>

                                <!-- Adiciona os respectivos dados a cada coluna -->
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-4 text-center whitespace-nowrap font-semibold">
                                        <?= htmlspecialchars($item['Patrimônio']) ?>
                                    </td>
                                    <td class="px-6 py-4 text-center whitespace-nowrap">
                                        <?= htmlspecialchars($item['Usuário']) ?>
                                    </td>
                                    <td class="px-6 py-4 text-center whitespace-nowrap">
                                        <span class="px-4 py-1.5 rounded-full text-sm font-semibold <?= $status_class ?>">
                                            <?= htmlspecialchars($status) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center whitespace-nowrap">
                                        <?= htmlspecialchars($item['Data de Aprovação']) ?>
                                    </td>
                                    <td class="px-6 py-4 text-center whitespace-nowrap">
                                        <?= htmlspecialchars($item['Validade']) ?>
                                    </td>
                                    <td class="px-6 py-4 text-center whitespace-nowrap">
                                        <?= htmlspecialchars($item['Aprovador']) ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        </tbody>
                    </table>
                </div>
            </div>
            <div class="flex flex-wrap justify-center items-center gap-2 mt-8">

                <!-- Lógica para criação de um botão para cada página -->
                <?php for ($i = 1; $i <= $total_paginas; $i++): ?>

                    <!-- Mantém os parâmetros na URL para que não haja perca dos filtros -->
                    <a href="?pagina=<?= $i ?>&busca=<?= urlencode($busca) ?>&ordenar=<?= urlencode($ordenar) ?>&direcao=<?= $direcao ?>"
                        class="px-4 py-1 rounded-lg border 
               <?= ($i == $pagina) ? 'bg-green-500 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">
                        <?= $i ?>
                    </a>

                <?php endfor; ?>

            </div>
        </div>
    </main>
</body>

</html>