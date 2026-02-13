<?php

require_once 'config.php';

session_start();

//Capturar número do patrimônio pela URL
$patrimonio = isset($_GET['patrimonio']) ? trim($_GET['patrimonio']) : null;
if (!$patrimonio) {
   echo "<script>
        alert('Informe o número de patrimônio na URL, ex: patrimonio=8350');
        window.history.back(); // volta para a página anterior (opcional)
    </script>";
    exit;
}
// Iniciar sessão no GLPI
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
    echo("Erro ao iniciar sessão: " . curl_error($curl));
}
curl_close($curl);

$data = json_decode($response, true);

if (isset($data['session_token'])) {
    $session_token['glpi_session_token'] = $data['session_token'];
    error_log("Sessão iniciada com sucesso");
} else {
    error_log("Erro ao iniciar sessão: " . $response);
}

function glpi_consult($endpoint, $session_token) {
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

$url_computer = $glpi_url . "/search/Computer?criteria[0][field]=6&criteria[0][searchtype]=equals&criteria[0][value]=$patrimonio&forcedisplay[0]=2&forcedisplay[1]=70"; //mostra informações com id do campo
//$url_computer = $glpi_url . "/search/Computer?expand_dropdowns=true";
$computer = glpi_consult($url_computer, $session_token);

$id = $computer['data'][0][2];

$url_plugin = $glpi_url . "/Computer/$id/PluginFieldsComputerautorizaoparasada?expand_dropdowns=true";
$plugin_data = glpi_consult($url_plugin, $session_token);
$plugin_item = $plugin_data[0] ?? [];

$status_id = $plugin_item['plugin_fields_statusdesadafielddropdowns_id'] ?? null;

if ($status_id == 1) {
    $status_name = "Não Autorizado";
    $status_bg = "bg-red-200";
    $status_color = "text-red-800";
} else {
    $status_name = "Autorizado";
    $status_bg = "bg-green-200";
    $status_color = "text-green-800";
}

$validade = $plugin_item['datadevalidadefield'] ?? null;
$validade_form = $validade ? date('d/m/y H:i:s', strtotime($validade)) : null;

$result_final = [
    'patrimônio' => $patrimonio,
    'Usuário' => $computer['data'][0][70] ?? 'Não encontrado',
    'Status de Saída' => $status_name ?? 'Não encontrado',
    'Data de Aprovação' => isset($plugin_item['datadeaprovaofield']) && $plugin_item['datadeaprovaofield'] 
        ? $plugin_item['datadeaprovaofield'] 
        : 'Não encontrado',
    'Validade' => $validade_form ?? 'Não encontrado',
    'Aprovador' => isset($plugin_item['users_id_aprovadorfield']) && $plugin_item['users_id_aprovadorfield'] 
        ? $plugin_item['users_id_aprovadorfield'] 
        : 'Não encontrado',
];

$template = file_get_contents("index.html");

$template = str_replace("{{PATRIMONIO}}", htmlspecialchars($patrimonio ?: 'Não encontrado'), $template);
$template = str_replace("{{USUARIO}}", htmlspecialchars($computer['data'][0][70] ?: 'Não encontrado'), $template);
$template = str_replace("{{STATUS}}", htmlspecialchars($status_name ?: 'Não encontrado'), $template);
$template = str_replace("{{DATA_APROV}}", htmlspecialchars($plugin_item['datadeaprovaofield'] ?: 'Não encontrado'), $template);
$template = str_replace("{{DATA_VAL}}", htmlspecialchars($validade_form ?: 'Não encontrado'), $template);
$template = str_replace("{{APROVADOR}}", htmlspecialchars($plugin_item['users_id_aprovadorfield'] ?: 'Não encontrado'), $template);
$template = str_replace("{{status_bg}}", htmlspecialchars($status_bg ?: 'Não encontrado'), $template);
$template = str_replace("{{status_color}}", htmlspecialchars($status_color ?: 'Não encontrado'), $template);

if (!isset($result_final)) {
    die("Computador não encontrado");
} else {
    echo($template);
};
