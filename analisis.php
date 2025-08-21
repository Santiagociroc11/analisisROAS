<?php
// --- MODO DE DEPURACIÓN Y ERRORES ---
// Poner en 'true' para ver mensajes de depuración. Poner en 'false' cuando todo funcione correctamente.
define('DEBUG_MODE', false);

if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    echo "<div id='debug_box' style='background: #fffbe6; padding: 15px; border: 1px solid #ffe58f; margin: 20px; border-radius: 8px; font-family: monospace; line-height: 1.6;'><strong>MODO DEPURACIÓN ACTIVADO:</strong><br>";
}

function debug_log($message) {
    if (DEBUG_MODE) {
        echo htmlspecialchars($message) . "<br>";
        if (ob_get_level() > 0) ob_flush();
        flush();
    }
}

// --- CONFIGURACIÓN DE LA BASE DE DATOS ---
define('DB_SERVER', '92.112.184.72');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'Jsdrevolution123');
define('DB_NAME', 'CAPTACIONES');
define('DB_PORT', 1296);

// --- FUNCIONES AUXILIARES ---
function clean_display_name($name) {
    // Función para limpiar nombres para mostrar en la interfaz (sin normalizar case/acentos)
    if (empty($name) || is_null($name)) {
        return '';
    }
    
    $name = (string)$name;
    
    // Limpiar prefijos y sufijos específicos de Meta/Facebook Ads
    $name = preg_replace('/^\{\{adsutm_content=/', '', $name);
    $name = preg_replace('/^\{\{adset\.name\}\}$/', '', $name);
    $name = preg_replace('/^\{\{[^}]*\}\}/', '', $name);
    $name = preg_replace('/\.mp4$/', '', $name);
    $name = preg_replace('/\.mov$/', '', $name);
    $name = preg_replace('/\.avi$/', '', $name);
    $name = preg_replace('/\.mkv$/', '', $name);
    $name = preg_replace('/\.wmv$/', '', $name);
    $name = preg_replace('/\.(jpg|jpeg|png|gif|webp)$/', '', $name);
    
    // Limpiar caracteres especiales de Meta Ads
    $name = str_replace(['{{', '}}', '='], '', $name);
    
    // Manejar casos especiales
    if (trim($name) === '-' || trim($name) === '' || trim($name) === 'undefined') {
        return '[Sin nombre]';
    }
    
    return trim($name);
}

function normalize_ad_name($name) {
    // Primero limpiar para mostrar
    $cleaned = clean_display_name($name);
    
    // Si está vacío después de limpiar, devolver vacío
    if (empty($cleaned) || $cleaned === '[Sin nombre]') {
        return '';
    }
    
    // Convertir a minúsculas
    $normalized = mb_strtolower($cleaned, 'UTF-8');
    
    // Quitar tildes y acentos
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
    
    // Si iconv falla, mantener el texto original
    if ($normalized === false) {
        $normalized = mb_strtolower($cleaned, 'UTF-8');
    }
    
    // Quitar caracteres especiales problemáticos, mantener solo letras, números, espacios y guiones
    $normalized = preg_replace('/[^a-z0-9\s\-_]/', '', $normalized);
    
    // Normalizar espacios múltiples a uno solo
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    
    // Quitar espacios al inicio y final
    $normalized = trim($normalized);
    
    return $normalized;
}

function get_available_tables() {
    $tables = [];
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT);
        $conn->set_charset("utf8mb4");
        $result = $conn->query("SHOW TABLES");
        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }
        $conn->close();
        return $tables;
    } catch (mysqli_sql_exception $e) {
        debug_log("Error obteniendo tablas: " . $e->getMessage());
        return [];
    }
}

// --- VARIABLES GLOBALES ---
$error_message = '';
$file_uploaded = false;
$interactive_data = null;
$quality_data = null;
$available_tables = get_available_tables();

// --- LÓGICA PRINCIPAL ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['spend_report'])) {
    debug_log("Petición POST recibida.");
    
    // Validar que se hayan seleccionado las tablas
    if (empty($_POST['base_table']) || empty($_POST['sales_table'])) {
        $error_message = "Debes seleccionar tanto la tabla base como la tabla de ventas.";
        debug_log("ERROR: " . $error_message);
    } else if ($_FILES['spend_report']['error'] == UPLOAD_ERR_OK) {
        $file_uploaded = true;
        $csv_file_path = $_FILES['spend_report']['tmp_name'];
        $selected_base_table = $_POST['base_table'];
        $selected_sales_table = $_POST['sales_table'];
        $multiply_revenue = isset($_POST['multiply_revenue']) && $_POST['multiply_revenue'] == '1';

        debug_log("Procesando archivo CSV de gastos...");
        $spend_result = process_spend_csv($csv_file_path);
        $segmentations_data = $spend_result['segmentations'];
        $spend_mapping = $spend_result['mapping'];
        debug_log("Archivo CSV procesado. " . count($segmentations_data) . " segmentaciones únicas con gastos encontradas.");

        if (!empty($segmentations_data)) {
            debug_log("Segmentaciones procesadas exitosamente: " . count($segmentations_data));
            debug_log("Obteniendo datos de ingresos desde la base de datos...");
            debug_log("Tabla base: " . $selected_base_table . ", Tabla ventas: " . $selected_sales_table);
            if ($multiply_revenue) {
                debug_log("Multiplicando ingresos x2 para análisis de coproducción total");
            }
            $revenue_data = get_revenue_data($selected_base_table, $selected_sales_table, $multiply_revenue);

            if ($revenue_data !== null) {
                debug_log(count($revenue_data) . " registros de ingresos (por anuncio/segmentación) encontrados.");
                debug_log("Construyendo estructura de datos interactiva...");
                $interactive_data = build_interactive_data($revenue_data, $segmentations_data, $spend_mapping, $multiply_revenue);
                debug_log("Estructura de datos creada. Total de anuncios procesados: " . count($interactive_data['ads']));
                
                // Obtener datos de calidad de leads para la segunda pestaña
                debug_log("Obteniendo datos de calidad de leads...");
                $quality_lead_data = get_quality_lead_data($selected_base_table, $selected_sales_table, $multiply_revenue);
                if ($quality_lead_data !== null) {
                    debug_log(count($quality_lead_data) . " registros de calidad de leads encontrados.");
                    $quality_data = build_quality_analysis($quality_lead_data, $segmentations_data, $spend_mapping, $multiply_revenue);
                    debug_log("Análisis de calidad completado.");
                }
                
                // Debug: Mostrar una muestra de los datos para verificar cálculos
                if (DEBUG_MODE && !empty($interactive_data['ads'])) {
                    $sample_ad = array_values($interactive_data['ads'])[0];
                    debug_log("MUESTRA DE DATOS - Anuncio: " . $sample_ad['ad_name_display']);
                    debug_log("- Total Revenue: " . $sample_ad['total_revenue']);
                    debug_log("- Total Spend: " . $sample_ad['total_spend']);
                    debug_log("- ROAS: " . $sample_ad['roas']);
                    debug_log("- Profit: " . $sample_ad['profit']);
                    if (!empty($sample_ad['segmentations'])) {
                        $sample_seg = $sample_ad['segmentations'][0];
                        debug_log("- Segmentación muestra: " . $sample_seg['name']);
                        debug_log("  - Revenue: " . $sample_seg['revenue']);
                        debug_log("  - Spend Allocated: " . $sample_seg['spend_allocated']);
                        debug_log("  - Profit: " . $sample_seg['profit']);
                        debug_log("  - CPL: " . $sample_seg['cpl']);
                    }
                }
                
                // Log de normalización y coincidencias para debug
                $normalized_count = 0;
                $matched_normalized = 0;
                $total_csv_ads = count($segmentations_data);
                $matched_ads = count($interactive_data['ads']);
                $unmatched_ads = $total_csv_ads - $matched_ads;
                
                foreach ($spend_mapping as $normalized => $original) {
                    $was_normalized = ($normalized !== normalize_ad_name($original));
                    if ($was_normalized) {
                        $normalized_count++;
                        debug_log("Normalizado: '$original' → '$normalized'");
                    }
                    // Verificar si este anuncio normalizado encontró coincidencia en los datos de revenue
                    foreach ($revenue_data as $rev_item) {
                        if ($rev_item['ANUNCIO_NORMALIZED'] === $normalized) {
                            if ($was_normalized) $matched_normalized++;
                            break;
                        }
                    }
                }
                
                if ($normalized_count > 0) {
                    debug_log("Se normalizaron $normalized_count nombres de anuncios. $matched_normalized encontraron coincidencia en la BD.");
                }
                
                debug_log("Resumen de coincidencias: $matched_ads de $total_csv_ads anuncios del CSV encontraron datos en la BD.");
                if ($unmatched_ads > 0) {
                    debug_log("⚠️ $unmatched_ads anuncios del CSV no encontraron coincidencias en la BD (posiblemente tienen gasto pero no generaron leads).");
                }
                
                debug_log("Estructura de datos creada. Análisis completado.");
            }
        } else {
            $error_message = "No se pudieron procesar los datos de gastos del archivo CSV.";
            debug_log("ERROR: " . $error_message);
        }
    } else {
        $error_message = 'Error al subir el archivo. Código: ' . $_FILES['spend_report']['error'];
        debug_log("ERROR: " . $error_message);
    }
}

function process_spend_csv($file_path) {
    global $error_message;
    $segmentations = [];
    $spend_mapping = []; // Para mantener el mapeo de nombres normalizados a originales
    
    if (($handle = fopen($file_path, "r")) !== FALSE) {
        $header = fgetcsv($handle, 1000, ",");
        if(!$header) { $error_message = "CSV vacío."; return []; }
        $header = array_map('trim', array_map('strtolower', $header));
        
        // Buscar las columnas necesarias
        $campaign_index = array_search('campaign name', $header);
        $ad_set_index = array_search('ad set name', $header);
        $ad_name_index = array_search('ad name', $header);
        $amount_spent_index = array_search('amount spent', $header);
        $placement_index = array_search('placement', $header);
        $platform_index = array_search('platform', $header);
        
        if ($campaign_index === false || $ad_set_index === false || $ad_name_index === false || $amount_spent_index === false) {
            $error_message = "CSV debe contener 'Campaign Name', 'Ad Set Name', 'Ad Name' y 'Amount Spent'.";
            return [];
        }
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($data) > max($campaign_index, $ad_set_index, $ad_name_index, $amount_spent_index)) {
                $campaign_name = trim($data[$campaign_index]);
                $ad_set_name = trim($data[$ad_set_index]);
                $ad_name_original = trim($data[$ad_name_index]);
                $placement = $placement_index !== false ? trim($data[$placement_index]) : '';
                $platform = $platform_index !== false ? trim($data[$platform_index]) : '';
                $amount = floatval(str_replace(',', '.', $data[$amount_spent_index]));
                
                if (!empty($campaign_name) && !empty($ad_set_name) && !empty($ad_name_original)) {
                    $ad_name_normalized = normalize_ad_name($ad_name_original);
                    
                    // Usar Ad Set Name como segmentación real
                    $segmentation_name = $ad_set_name;
                    
                    // Clave única para esta segmentación específica
                    $unique_key = $ad_name_normalized . '|' . normalize_ad_name($segmentation_name);
                    
                    if (!isset($segmentations[$unique_key])) {
                        $segmentations[$unique_key] = [
                            'campaign_name' => $campaign_name,
                            'ad_set_name' => $ad_set_name,
                            'ad_name_original' => $ad_name_original,
                            'ad_name_normalized' => $ad_name_normalized,
                            'segmentation_name' => $segmentation_name,
                            'spend' => 0
                        ];
                        
                        // Mantener mapeo del nombre normalizado al original
                        if (!isset($spend_mapping[$ad_name_normalized])) {
                            $spend_mapping[$ad_name_normalized] = $ad_name_original;
                        }
                    }
                    
                    $segmentations[$unique_key]['spend'] += $amount;
                }
            }
        }
        fclose($handle);
    }
    
    debug_log("CSV procesado: " . count($segmentations) . " segmentaciones únicas encontradas.");
    
    // Devolver las segmentaciones y el mapeo
    return ['segmentations' => $segmentations, 'mapping' => $spend_mapping];
}

function get_revenue_data($base_table, $sales_table, $multiply_revenue = false) {
    global $error_message;
    $revenue = [];
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT);
        $conn->set_charset("utf8mb4");
        
        // Sanitizar nombres de tablas para prevenir inyección SQL
        $base_table = mysqli_real_escape_string($conn, $base_table);
        $sales_table = mysqli_real_escape_string($conn, $sales_table);
        
        // Multiplicar ingresos por 2 si está habilitado (para coproducción 50/50)
        $revenue_multiplier = $multiply_revenue ? '* 2' : '';
        
        $sql = "
            SELECT
                l.ANUNCIO,
                l.SEGMENTACION,
                l.CAMPAÑA,
                COUNT(l.`#`) AS total_leads,
                COUNT(v.cliente_id) AS total_sales,
                COALESCE(SUM(CAST(REPLACE(v.monto, ',', '.') AS DECIMAL(10, 2))) {$revenue_multiplier}, 0) AS total_revenue
            FROM `{$base_table}` AS l
            LEFT JOIN `{$sales_table}` AS v ON l.`#` = v.cliente_id
            WHERE l.ANUNCIO IS NOT NULL AND l.ANUNCIO != '' AND l.SEGMENTACION IS NOT NULL
            GROUP BY l.ANUNCIO, l.SEGMENTACION, l.CAMPAÑA;
        ";
        $result = $conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            // Normalizar el nombre del anuncio y segmentación para la comparación
            $row['ANUNCIO_NORMALIZED'] = normalize_ad_name($row['ANUNCIO']);
            $row['SEGMENTACION_NORMALIZED'] = normalize_ad_name($row['SEGMENTACION']);
            $revenue[] = $row;
        }
        $conn->close();
        return $revenue;
    } catch (mysqli_sql_exception $e) {
        $error_message = "Error de Base de Datos: " . $e->getMessage();
        debug_log("FALLO LA CONEXIÓN/CONSULTA: " . $error_message);
        return null;
    }
}

function get_quality_lead_data($base_table, $sales_table, $multiply_revenue = false) {
    global $error_message;
    $quality_data = [];
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT);
        $conn->set_charset("utf8mb4");
        
        // Sanitizar nombres de tablas para prevenir inyección SQL
        $base_table = mysqli_real_escape_string($conn, $base_table);
        $sales_table = mysqli_real_escape_string($conn, $sales_table);
        
        // Multiplicar ingresos por 2 si está habilitado (para coproducción 50/50)
        $revenue_multiplier = $multiply_revenue ? '* 2' : '';
        
        $sql = "
            SELECT
                l.ANUNCIO,
                l.SEGMENTACION,
                l.CAMPAÑA,
                l.QLEAD,
                l.INGRESOS,
                l.ESTUDIOS,
                l.OCUPACION,
                l.PROPOSITO,
                l.PUNTAJE,
                COUNT(l.`#`) AS total_leads,
                COUNT(v.cliente_id) AS total_sales,
                COALESCE(SUM(CAST(REPLACE(v.monto, ',', '.') AS DECIMAL(10, 2))) {$revenue_multiplier}, 0) AS total_revenue
            FROM `{$base_table}` AS l
            LEFT JOIN `{$sales_table}` AS v ON l.`#` = v.cliente_id
            WHERE l.ANUNCIO IS NOT NULL AND l.ANUNCIO != '' AND l.SEGMENTACION IS NOT NULL
            GROUP BY l.ANUNCIO, l.SEGMENTACION, l.CAMPAÑA, l.QLEAD, l.INGRESOS, l.ESTUDIOS, l.OCUPACION, l.PROPOSITO, l.PUNTAJE;
        ";
        $result = $conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            // Normalizar el nombre del anuncio y segmentación para la comparación
            $row['ANUNCIO_NORMALIZED'] = normalize_ad_name($row['ANUNCIO']);
            $row['SEGMENTACION_NORMALIZED'] = normalize_ad_name($row['SEGMENTACION']);
            $quality_data[] = $row;
        }
        $conn->close();
        return $quality_data;
    } catch (mysqli_sql_exception $e) {
        $error_message = "Error de Base de Datos: " . $e->getMessage();
        debug_log("FALLO LA CONEXIÓN/CONSULTA: " . $error_message);
        return null;
    }
}

function build_interactive_data($revenue_data, $segmentations_data, $spend_mapping, $multiply_revenue = false) {
    $ads = [];
    $total_revenue_all = 0;
    $total_spend_all = 0;

    // Primero: Agrupar ingresos por anuncio y segmentación desde la BD
    foreach ($revenue_data as $rev_item) {
        $ad_name_original = $rev_item['ANUNCIO'];
        $ad_name_normalized = $rev_item['ANUNCIO_NORMALIZED'];
        $segmentation_original = $rev_item['SEGMENTACION'];
        $segmentation_normalized = $rev_item['SEGMENTACION_NORMALIZED'];
        $campaign_name = $rev_item['CAMPAÑA'] ?? '';
        $revenue = (float)$rev_item['total_revenue'];
        $leads = (int)$rev_item['total_leads'];
        $sales = (int)$rev_item['total_sales'];

        // Usar el nombre normalizado como clave para agrupar
        if (!isset($ads[$ad_name_normalized])) {
            // Decidir qué nombre mostrar: el del CSV si existe, sino el de la BD (ambos limpios)
            $display_name = isset($spend_mapping[$ad_name_normalized]) 
                ? clean_display_name($spend_mapping[$ad_name_normalized])
                : clean_display_name($ad_name_original);
                
            $ads[$ad_name_normalized] = [
                'ad_name_display' => $display_name,
                'total_revenue' => 0,
                'total_leads' => 0,
                'total_sales' => 0,
                'total_spend' => 0,
                'roas' => 0,
                'segmentations' => []
            ];
        }
        
        // Buscar si ya existe esta segmentación (comparar SEGMENTACION de BD con Ad Set Name del CSV)
        $seg_found = false;
        foreach ($ads[$ad_name_normalized]['segmentations'] as &$existing_seg) {
            if (normalize_ad_name($existing_seg['name']) === $segmentation_normalized) {
                // Verificar que no se dupliquen métricas de la misma fila de BD
                $unique_bd_key = $ad_name_normalized . '|' . $segmentation_normalized . '|' . $revenue . '|' . $leads;
                if (!isset($existing_seg['processed_bd_keys'])) {
                    $existing_seg['processed_bd_keys'] = [];
                }
                
                if (!in_array($unique_bd_key, $existing_seg['processed_bd_keys'])) {
                    // Agregar a la segmentación existente solo si no se ha procesado antes
                    $existing_seg['revenue'] += $revenue;
                    $existing_seg['leads'] += $leads;
                    $existing_seg['sales'] += $sales;
                    $existing_seg['processed_bd_keys'][] = $unique_bd_key;
                }
                $existing_seg['campaign_name'] = $campaign_name; // Asegurar que tenga la campaña
                $existing_seg['conversion_rate'] = $existing_seg['leads'] > 0 ? ($existing_seg['sales'] / $existing_seg['leads']) * 100 : 0;
                $seg_found = true;
                break;
            }
        }
        unset($existing_seg); // Romper referencia
        
        // Si no se encontró, crear nueva segmentación
        if (!$seg_found) {
            $ads[$ad_name_normalized]['segmentations'][] = [
                'name' => clean_display_name($segmentation_original), // Mostrar nombre limpio
                'campaign_name' => $campaign_name,
                'revenue' => $revenue,
                'leads' => $leads,
                'sales' => $sales,
                'spend_allocated' => 0, // Se asignará después con datos del CSV
                'profit' => $revenue, // Profit inicial = revenue (sin gasto aún)
                'cpl' => 0, // Sin gasto, CPL es 0
                'conversion_rate' => $leads > 0 ? ($sales / $leads) * 100 : 0
            ];
        }
        
        $ads[$ad_name_normalized]['total_revenue'] += $revenue;
        $ads[$ad_name_normalized]['total_leads'] += $leads;
        $ads[$ad_name_normalized]['total_sales'] += $sales;
    }

    // Segundo: Asignar gastos reales desde el CSV a cada segmentación
    foreach ($segmentations_data as $seg_data) {
        $ad_name_normalized = $seg_data['ad_name_normalized'];
        $segmentation_name = $seg_data['segmentation_name'];
        $segmentation_normalized = normalize_ad_name($segmentation_name);
        $spend = $seg_data['spend'];
        
        // Si existe el anuncio en nuestros datos de revenue
        if (isset($ads[$ad_name_normalized])) {
            // Buscar la segmentación correspondiente
            $seg_found = false;
            foreach ($ads[$ad_name_normalized]['segmentations'] as &$existing_seg) {
                if (normalize_ad_name($existing_seg['name']) === $segmentation_normalized) {
                    $existing_seg['spend_allocated'] += $spend; // Acumular gasto total
                    $existing_seg['profit'] = $existing_seg['revenue'] - $existing_seg['spend_allocated'];
                    $existing_seg['cpl'] = ($existing_seg['leads'] > 0) ? $existing_seg['spend_allocated'] / $existing_seg['leads'] : 0;
                    $seg_found = true;
                    if (DEBUG_MODE) {
                        debug_log("COINCIDENCIA ENCONTRADA - Anuncio: $ad_name_normalized, Segmentación: $segmentation_normalized, Gasto: $spend");
                    }
                    break;
                }
            }
            unset($existing_seg);
            
            // Si no se encontró la segmentación en los datos de revenue, crear una nueva
            if (!$seg_found) {
                if (DEBUG_MODE) {
                    debug_log("NO SE ENCONTRÓ SEGMENTACIÓN - Anuncio: $ad_name_normalized, Segmentación: $segmentation_normalized");
                }
                $ads[$ad_name_normalized]['segmentations'][] = [
                    'name' => clean_display_name($segmentation_name), // Mostrar nombre limpio
                    'campaign_name' => $seg_data['campaign_name'],
                    'revenue' => 0,
                    'leads' => 0,
                    'sales' => 0,
                    'spend_allocated' => $spend,
                    'profit' => -$spend, // Solo gasto, sin ingresos
                    'cpl' => 0,
                    'conversion_rate' => 0
                ];
            }
            
            $ads[$ad_name_normalized]['total_spend'] += $spend;
        } else {
            // Anuncio con gasto pero sin datos de revenue en la BD
            $display_name = clean_display_name($seg_data['ad_name_original']);
            
            $ads[$ad_name_normalized] = [
                'ad_name_display' => $display_name,
                'total_revenue' => 0,
                'total_leads' => 0,
                'total_sales' => 0,
                'total_spend' => $spend,
                'roas' => 0,
                'segmentations' => [[
                    'name' => clean_display_name($segmentation_name), // Mostrar nombre limpio
                    'campaign_name' => $seg_data['campaign_name'],
                    'revenue' => 0,
                    'leads' => 0,
                    'sales' => 0,
                    'spend_allocated' => $spend,
                    'profit' => -$spend,
                    'cpl' => 0,
                    'conversion_rate' => 0
                ]]
            ];
        }
        
        $total_spend_all += $spend;
    }

    // Tercero: Calcular ROAS y utilidad general por anuncio
    foreach ($ads as $ad_name_normalized => &$ad_data) {
        if ($ad_data['total_spend'] > 0) {
            $ad_data['roas'] = $ad_data['total_revenue'] / $ad_data['total_spend'];
        } else {
            $ad_data['roas'] = 0; // Inicializar ROAS cuando no hay gasto
        }
        $ad_data['profit'] = $ad_data['total_revenue'] - $ad_data['total_spend'];
        $total_revenue_all += $ad_data['total_revenue'];
        
        // Recalcular profit para todas las segmentaciones (en caso de que no se haya actualizado)
        foreach ($ad_data['segmentations'] as &$seg) {
            if (!isset($seg['profit']) || $seg['profit'] === null) {
                $seg['profit'] = $seg['revenue'] - ($seg['spend_allocated'] ?? 0);
            }
            // Recalcular CPL también
            if (!isset($seg['cpl']) || $seg['cpl'] === null) {
                $seg['cpl'] = ($seg['leads'] > 0 && ($seg['spend_allocated'] ?? 0) > 0) ? ($seg['spend_allocated'] ?? 0) / $seg['leads'] : 0;
            }
        }
        unset($seg); // Romper referencia
        
        // Ordenar segmentaciones por ingresos
        usort($ad_data['segmentations'], function($a, $b) {
            return $b['revenue'] <=> $a['revenue'];
        });
    }
    unset($ad_data);

    // Ordenar anuncios por utilidad (más rentable primero)
    uasort($ads, function($a, $b) {
        return $b['profit'] <=> $a['profit'];
    });

    $total_roas_all = ($total_spend_all > 0) ? $total_revenue_all / $total_spend_all : 0;
    $best_ad_key = !empty($ads) ? array_key_first($ads) : null;

    return [
        'summary' => [
            'total_revenue' => $total_revenue_all,
            'total_spend' => $total_spend_all,
            'total_roas' => $total_roas_all,
            'best_performing_ad_key' => $best_ad_key,
            'multiply_revenue' => $multiply_revenue
        ],
        'ads' => $ads
    ];
}

function build_quality_analysis($quality_lead_data, $segmentations_data, $spend_mapping, $multiply_revenue = false) {
    $quality_segments = [];
    $total_revenue_all = 0;
    $total_spend_all = 0;

    // Agrupar por categorías de calidad
    foreach ($quality_lead_data as $row) {
        $qlead = !empty($row['QLEAD']) ? $row['QLEAD'] : 'Sin Clasificar';
        $ingresos = !empty($row['INGRESOS']) ? $row['INGRESOS'] : 'No Especificado';
        $estudios = !empty($row['ESTUDIOS']) ? $row['ESTUDIOS'] : 'No Especificado';
        $ocupacion = !empty($row['OCUPACION']) ? $row['OCUPACION'] : 'No Especificado';
        $proposito = !empty($row['PROPOSITO']) ? $row['PROPOSITO'] : 'No Especificado';
        $puntaje = !empty($row['PUNTAJE']) ? floatval($row['PUNTAJE']) : 0;
        
        $ad_name_normalized = $row['ANUNCIO_NORMALIZED'];
        $segmentation_normalized = $row['SEGMENTACION_NORMALIZED'];
        $revenue = (float)$row['total_revenue'];
        $leads = (int)$row['total_leads'];
        $sales = (int)$row['total_sales'];
        
        // Crear clave única para este segmento de calidad
        $quality_key = $qlead . '|' . $ingresos . '|' . $estudios . '|' . $ocupacion;
        
        if (!isset($quality_segments[$quality_key])) {
            $quality_segments[$quality_key] = [
                'qlead' => $qlead,
                'ingresos' => $ingresos,
                'estudios' => $estudios,
                'ocupacion' => $ocupacion,
                'proposito' => $proposito,
                'avg_puntaje' => 0,
                'total_leads' => 0,
                'total_sales' => 0,
                'total_revenue' => 0,
                'total_spend' => 0,
                'roas' => 0,
                'conversion_rate' => 0,
                'ads' => []
            ];
        }
        
        // Acumular métricas
        $quality_segments[$quality_key]['total_leads'] += $leads;
        $quality_segments[$quality_key]['total_sales'] += $sales;
        $quality_segments[$quality_key]['total_revenue'] += $revenue;
        
        // Agregar información del anuncio
        $ad_key = $ad_name_normalized . '|' . $segmentation_normalized;
        if (!isset($quality_segments[$quality_key]['ads'][$ad_key])) {
            $quality_segments[$quality_key]['ads'][$ad_key] = [
                'ad_name' => clean_display_name($row['ANUNCIO']),
                'segmentation' => clean_display_name($row['SEGMENTACION']),
                'campaign' => $row['CAMPAÑA'],
                'leads' => 0,
                'sales' => 0,
                'revenue' => 0,
                'spend' => 0,
                'puntaje_sum' => 0,
                'puntaje_count' => 0
            ];
        }
        
        $quality_segments[$quality_key]['ads'][$ad_key]['leads'] += $leads;
        $quality_segments[$quality_key]['ads'][$ad_key]['sales'] += $sales;
        $quality_segments[$quality_key]['ads'][$ad_key]['revenue'] += $revenue;
        $quality_segments[$quality_key]['ads'][$ad_key]['puntaje_sum'] += $puntaje * $leads;
        $quality_segments[$quality_key]['ads'][$ad_key]['puntaje_count'] += $leads;
    }
    
    // Asignar gastos desde el CSV y calcular métricas finales
    foreach ($segmentations_data as $seg_data) {
        $ad_name_normalized = $seg_data['ad_name_normalized'];
        $segmentation_normalized = normalize_ad_name($seg_data['segmentation_name']);
        $spend = $seg_data['spend'];
        $ad_key = $ad_name_normalized . '|' . $segmentation_normalized;
        
        // Distribuir gasto proporcional por cada segmento de calidad
        foreach ($quality_segments as &$quality_seg) {
            if (isset($quality_seg['ads'][$ad_key])) {
                $quality_seg['ads'][$ad_key]['spend'] = $spend;
                $quality_seg['total_spend'] += $spend;
                $total_spend_all += $spend;
            }
        }
        unset($quality_seg);
    }
    
    // Calcular métricas finales para cada segmento
    foreach ($quality_segments as $key => &$segment) {
        $segment['conversion_rate'] = $segment['total_leads'] > 0 ? ($segment['total_sales'] / $segment['total_leads']) * 100 : 0;
        $segment['roas'] = $segment['total_spend'] > 0 ? $segment['total_revenue'] / $segment['total_spend'] : 0;
        $segment['profit'] = $segment['total_revenue'] - $segment['total_spend'];
        $segment['cpl'] = $segment['total_leads'] > 0 && $segment['total_spend'] > 0 ? $segment['total_spend'] / $segment['total_leads'] : 0;
        
        // Calcular puntaje promedio ponderado
        $total_puntaje_sum = 0;
        $total_puntaje_count = 0;
        foreach ($segment['ads'] as &$ad) {
            if ($ad['puntaje_count'] > 0) {
                $ad['avg_puntaje'] = $ad['puntaje_sum'] / $ad['puntaje_count'];
                $total_puntaje_sum += $ad['puntaje_sum'];
                $total_puntaje_count += $ad['puntaje_count'];
            } else {
                $ad['avg_puntaje'] = 0;
            }
        }
        unset($ad);
        
        $segment['avg_puntaje'] = $total_puntaje_count > 0 ? $total_puntaje_sum / $total_puntaje_count : 0;
        $total_revenue_all += $segment['total_revenue'];
        
        // Ordenar anuncios por profit
        uasort($segment['ads'], function($a, $b) {
            return ($b['revenue'] - $b['spend']) - ($a['revenue'] - $a['spend']);
        });
    }
    unset($segment);
    
    // Ordenar segmentos por ROAS
    uasort($quality_segments, function($a, $b) {
        return $b['roas'] - $a['roas'];
    });
    
    $total_roas_all = ($total_spend_all > 0) ? $total_revenue_all / $total_spend_all : 0;
    
    return [
        'summary' => [
            'total_revenue' => $total_revenue_all,
            'total_spend' => $total_spend_all,
            'total_roas' => $total_roas_all,
            'multiply_revenue' => $multiply_revenue
        ],
        'segments' => $quality_segments
    ];
}

if (DEBUG_MODE) {
    echo "</div>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Interactivo de ROAS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .card { background-color: white; border-radius: 0.75rem; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); }
        .main-item.active { background-color: #eef2ff; }
        .main-item.active td:first-child {
            border-left: 3px solid #4f46e5;
            padding-left: 5px; /* (8px - 3px) */
        }
        .main-item.active, .main-item.active:hover {
            background-color: #eef2ff;
        }
        .main-item td { color: inherit !important; }
        .main-item { transition: background-color 0.2s ease-in-out, color 0.2s ease-in-out; }
        
        /* Estilos para tablas ordenables */
        th.sortable { 
            cursor: pointer; 
            user-select: none;
            transition: background-color 0.2s;
        }
        th.sortable:hover { 
            background-color: #f9fafb; 
        }
        
        /* Scroll personalizado */
        .overflow-auto::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .overflow-auto::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        .overflow-auto::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        .overflow-auto::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        /* Filas zebra para mejor legibilidad */
        tbody tr:nth-child(even) {
            background-color: #fafafa;
        }
        tbody tr:hover {
            background-color: #f3f4f6;
        }
        .main-item:hover td {
            color: inherit;
        }
        
        /* Mejorar espaciado de filas en tabla principal */
        #main-table tbody tr {
            vertical-align: top;
        }
        #main-table tbody td {
            padding-top: 8px;
            padding-bottom: 8px;
        }
    </style>
</head>
<body>

    <div class="container mx-auto p-4 md:p-6">
        <header class="text-center mb-6">
            <h1 class="text-3xl md:text-4xl font-bold text-gray-900">Dashboard Interactivo de ROAS</h1>
            <p class="text-gray-600 mt-2">Analiza el rendimiento de tus campañas de forma visual e interactiva.</p>
        </header>

        <?php if (!$file_uploaded || $error_message): ?>
        <div class="card max-w-4xl mx-auto p-6">
            <h2 class="text-xl font-semibold mb-4 border-b pb-2">Configuración del Análisis</h2>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data" class="space-y-6">
                
                <!-- Selección de Tablas -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="base_table" class="block text-sm font-medium text-gray-700 mb-2">1. Selecciona la Tabla Base (Leads/Prospectos)</label>
                        <select name="base_table" id="base_table" required class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">-- Selecciona la tabla base --</option>
                            <?php foreach ($available_tables as $table): ?>
                            <option value="<?php echo htmlspecialchars($table); ?>" <?php echo (isset($_POST['base_table']) && $_POST['base_table'] == $table) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($table); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Tabla que contiene ANUNCIO, SEGMENTACION y # (ID)</p>
                    </div>
                    
                    <div>
                        <label for="sales_table" class="block text-sm font-medium text-gray-700 mb-2">2. Selecciona la Tabla de Ventas</label>
                        <select name="sales_table" id="sales_table" required class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">-- Selecciona la tabla de ventas --</option>
                            <?php foreach ($available_tables as $table): ?>
                            <option value="<?php echo htmlspecialchars($table); ?>" <?php echo (isset($_POST['sales_table']) && $_POST['sales_table'] == $table) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($table); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Tabla que contiene cliente_id y monto</p>
                    </div>
                </div>
                
                <!-- Opciones de Análisis -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">3. Opciones de Análisis</label>
                    <div class="flex items-center space-x-4 p-3 bg-gray-50 rounded-md">
                        <label class="flex items-center">
                            <input type="checkbox" name="multiply_revenue" id="multiply_revenue" value="1" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded" <?php echo (isset($_POST['multiply_revenue']) && $_POST['multiply_revenue']) ? 'checked' : ''; ?>>
                            <span class="ml-2 text-sm text-gray-700">
                                <strong>Multiplicar ingresos x2</strong>
                                <span class="block text-xs text-gray-500">Para proyectos de coproducción 50/50 donde quieres analizar el total</span>
                            </span>
                        </label>
                    </div>
                </div>
                
                <!-- Carga de Archivo CSV -->
                <div>
                    <label for="spend_report" class="block text-sm font-medium text-gray-700 mb-2">4. Cargar Reporte de Gastos (CSV)</label>
                    <input type="file" name="spend_report" id="spend_report" required class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 transition-colors cursor-pointer">
                    <p class="text-xs text-gray-500 mt-1">Archivo CSV que debe contener columnas 'Ad Name' y 'Amount Spent'</p>
                </div>
                
                <!-- Botón de Envío -->
                <div class="flex justify-center">
                    <button type="submit" class="bg-indigo-600 text-white font-bold py-3 px-8 rounded-md hover:bg-indigo-700 transition-transform transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-opacity-50">
                        🚀 Analizar Datos del Proyecto
                </button>
                </div>
            </form>
            
            <?php if ($error_message): ?>
            <div class="mt-6 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md" role="alert">
                <p class="font-bold">Error</p>
                <p><?php echo htmlspecialchars($error_message); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($file_uploaded && !$error_message && $interactive_data): ?>
        
        <!-- Navegación por pestañas -->
        <div class="mb-6">
            <div class="border-b border-gray-200">
                <nav class="-mb-px flex space-x-8">
                    <button id="tab-general" class="tab-button active border-b-2 border-indigo-500 py-4 px-1 text-sm font-medium text-indigo-600">
                        📊 Análisis General
                    </button>
                    <?php if ($quality_data): ?>
                    <button id="tab-quality" class="tab-button border-b-2 border-transparent py-4 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300">
                        🎯 Análisis por Calidad de Leads
                    </button>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
        
        <!-- Información del Proyecto Analizado -->
        <div class="mb-6">
            <div class="card p-4 bg-blue-50 border-l-4 border-blue-500">
                <div class="flex justify-between items-start">
                    <div class="flex-1">
                        <h3 class="text-lg font-semibold text-blue-800 mb-2">📊 Proyecto en Análisis</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                            <div>
                                <span class="font-medium text-blue-700">Tabla Base:</span> 
                                <span class="text-blue-900"><?php echo htmlspecialchars($_POST['base_table']); ?></span>
                            </div>
                            <div>
                                <span class="font-medium text-blue-700">Tabla de Ventas:</span> 
                                <span class="text-blue-900"><?php echo htmlspecialchars($_POST['sales_table']); ?></span>
                            </div>
                            <?php if (isset($_POST['multiply_revenue']) && $_POST['multiply_revenue']): ?>
                            <div>
                                <span class="font-medium text-blue-700">Análisis:</span> 
                                <span class="text-blue-900 bg-amber-100 px-2 py-1 rounded text-xs font-bold">Ingresos x2 (Coproducción Total)</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php 
                        // Mostrar información de normalización y coincidencias
                        if (isset($spend_mapping) && isset($interactive_data)) {
                            $normalized_ads = 0;
                            $total_csv_ads = count($segmentations_data);
                            $matched_ads = count($interactive_data['ads']);
                            $unmatched_ads = $total_csv_ads - $matched_ads;
                            
                            foreach ($spend_mapping as $normalized => $original) {
                                if ($normalized !== normalize_ad_name($original)) {
                                    $normalized_ads++;
                                }
                            }
                            
                            echo "<div class='mt-3 text-xs space-y-1'>";
                            
                            if ($normalized_ads > 0) {
                                echo "<div class='text-blue-600'>";
                                echo "🔗 Se normalizaron $normalized_ads nombres de anuncios para mejorar la coincidencia (tildes, espacios, etc.)";
                                echo "</div>";
                            }
                            
                            echo "<div class='text-green-600'>";
                            echo "✅ $matched_ads de $total_csv_ads anuncios del CSV encontraron datos en la BD";
                            echo "</div>";
                            
                            if ($unmatched_ads > 0) {
                                echo "<div class='text-amber-600'>";
                                echo "⚠️ $unmatched_ads anuncios solo tienen gastos (sin leads/ventas en la BD)";
                                echo "</div>";
                            }
                            
                            echo "</div>";
                        }
                        ?>
                    </div>
                    <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="ml-4 bg-indigo-600 text-white text-sm font-medium py-2 px-4 rounded-md hover:bg-indigo-700 transition-colors">
                        📋 Analizar Otro Proyecto
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Toggle de Perspectiva -->
        <div class="mb-6">
            <div class="card p-4 bg-slate-50 border-l-4 border-slate-500">
                <div class="flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-800 mb-1">🔄 Perspectiva de Análisis</h3>
                        <p class="text-sm text-slate-600">Cambia cómo quieres analizar tus datos</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <label class="inline-flex items-center cursor-pointer">
                            <span class="text-sm font-medium text-gray-700 mr-3" id="perspective-label-left">Anuncio → Segmentaciones</span>
                            <input type="checkbox" id="perspective-toggle" class="sr-only peer">
                            <div class="relative w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            <span class="text-sm font-medium text-gray-700 ml-3" id="perspective-label-right">Segmentación → Anuncios</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Métricas Globales -->
        <div class="mb-6">
            <div class="flex justify-between items-center mb-3">
                <h2 class="text-xl font-bold text-gray-800">Resumen General</h2>
                <?php if ($interactive_data['summary']['multiply_revenue']): ?>
                <span class="bg-amber-100 text-amber-800 px-3 py-1 rounded-full text-xs font-bold">
                    📊 Ingresos Multiplicados x2
                </span>
                <?php endif; ?>
            </div>
            <div id="summary-cards" class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Las tarjetas de resumen se cargarán aquí con JS -->
            </div>
        </div>

        <!-- Contenido General -->
        <div id="content-general" class="tab-content">
            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Columna Izquierda: Lista Dinámica -->
                <aside class="lg:w-2/5 xl:w-1/3">
                    <div class="card p-4">
                    <div class="flex justify-between items-center mb-3">
                        <h2 class="text-lg font-bold" id="main-table-title">Análisis por Anuncio</h2>
                        <div class="flex items-center gap-2">
                            <button id="selectAllBtn" class="text-xs bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-1 px-2 rounded">
                                Todos
                            </button>
                            <button id="selectNoneBtn" class="text-xs bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-1 px-2 rounded">
                                Ninguno
                            </button>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mb-3">
                        Haz clic para seleccionar un anuncio. Mantén <kbd class="px-2 py-1.5 text-xs font-semibold text-gray-800 bg-gray-100 border border-gray-200 rounded-lg">Ctrl</kbd> o <kbd class="px-2 py-1.5 text-xs font-semibold text-gray-800 bg-gray-100 border border-gray-200 rounded-lg">Shift</kbd> para seleccionar varios.
                    </p>
                    <div class="mb-3">
                        <label for="sortBy" class="block text-xs font-medium text-gray-700 mb-1">Ordenar por:</label>
                        <select id="sortBy" class="block w-full text-xs border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            <option value="profit">Utilidad</option>
                            <option value="revenue">Ingresos</option>
                            <option value="spend">Gasto</option>
                            <option value="roas">ROAS</option>
                            <option value="name">Nombre</option>
                        </select>
                    </div>
                    <div class="max-h-[60vh] overflow-auto">
                        <table id="main-table" class="w-full text-xs table-fixed">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr id="main-table-header">
                                    <th class="px-2 py-2 text-left font-medium text-gray-700 w-2/5" id="main-column-name">Anuncio</th>
                                    <th class="px-1 py-2 text-right font-medium text-gray-700 w-1/6">Ingresos</th>
                                    <th class="px-1 py-2 text-right font-medium text-gray-700 w-1/6">Gasto</th>
                                    <th class="px-1 py-2 text-right font-medium text-gray-700 w-1/6">Utilidad</th>
                                    <th class="px-1 py-2 text-right font-medium text-gray-700 w-1/12">ROAS</th>
                                </tr>
                            </thead>
                            <tbody id="main-list">
                                <!-- El contenido se generará dinámicamente con JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </aside>

            <!-- Columna Derecha: Detalles del Anuncio -->
            <main class="lg:w-3/5 xl:w-2/3">
                <div id="details-view" class="space-y-6">
                    <!-- El contenido detallado se generará aquí con JS -->
                    <div id="details-placeholder" class="card text-center p-10">
                        <h3 class="text-xl font-semibold text-gray-700">Selecciona uno o más anuncios</h3>
                        <p class="text-gray-500">Los detalles y segmentaciones de tu selección aparecerán aquí.</p>
                    </div>
                </div>
            </main>
            </div>
        </div>

        <!-- Contenido de Calidad de Leads -->
        <?php if ($quality_data): ?>
        <div id="content-quality" class="tab-content hidden">
            <div class="space-y-6">
                <!-- Resumen de Calidad -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="card p-4 text-center bg-gradient-to-r from-purple-50 to-pink-50">
                        <h4 class="text-sm font-medium text-purple-700">Segmentos de Calidad</h4>
                        <p class="text-2xl font-bold text-purple-600"><?php echo count($quality_data['segments']); ?></p>
                    </div>
                    <div class="card p-4 text-center bg-gradient-to-r from-green-50 to-blue-50">
                        <h4 class="text-sm font-medium text-green-700">Ingresos Totales</h4>
                        <p class="text-2xl font-bold text-green-600"><?php echo number_format($quality_data['summary']['total_revenue'], 2); ?>$</p>
                    </div>
                    <div class="card p-4 text-center bg-gradient-to-r from-yellow-50 to-orange-50">
                        <h4 class="text-sm font-medium text-yellow-700">ROAS Promedio</h4>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($quality_data['summary']['total_roas'], 2); ?>x</p>
                    </div>
                    <div class="card p-4 text-center bg-gradient-to-r from-indigo-50 to-purple-50">
                        <h4 class="text-sm font-medium text-indigo-700">Gasto Total</h4>
                        <p class="text-2xl font-bold text-indigo-600"><?php echo number_format($quality_data['summary']['total_spend'], 2); ?>$</p>
                    </div>
                </div>

                <!-- Filtros para análisis de calidad -->
                <div class="card p-4 bg-gray-50">
                    <h3 class="text-lg font-semibold mb-3">🔍 Filtros de Análisis</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Por Calidad (QLEAD)</label>
                            <select id="filter-qlead" class="w-full text-sm border border-gray-300 rounded px-3 py-2">
                                <option value="">Todos</option>
                                <?php
                                $qleads = array_unique(array_column($quality_data['segments'], 'qlead'));
                                foreach ($qleads as $qlead): ?>
                                    <option value="<?php echo htmlspecialchars($qlead); ?>"><?php echo htmlspecialchars($qlead); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Por Ingresos</label>
                            <select id="filter-ingresos" class="w-full text-sm border border-gray-300 rounded px-3 py-2">
                                <option value="">Todos</option>
                                <?php
                                $ingresos_list = array_unique(array_column($quality_data['segments'], 'ingresos'));
                                foreach ($ingresos_list as $ingresos): ?>
                                    <option value="<?php echo htmlspecialchars($ingresos); ?>"><?php echo htmlspecialchars($ingresos); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Por Estudios</label>
                            <select id="filter-estudios" class="w-full text-sm border border-gray-300 rounded px-3 py-2">
                                <option value="">Todos</option>
                                <?php
                                $estudios_list = array_unique(array_column($quality_data['segments'], 'estudios'));
                                foreach ($estudios_list as $estudios): ?>
                                    <option value="<?php echo htmlspecialchars($estudios); ?>"><?php echo htmlspecialchars($estudios); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Por Ocupación</label>
                            <select id="filter-ocupacion" class="w-full text-sm border border-gray-300 rounded px-3 py-2">
                                <option value="">Todos</option>
                                <?php
                                $ocupaciones = array_unique(array_column($quality_data['segments'], 'ocupacion'));
                                foreach ($ocupaciones as $ocupacion): ?>
                                    <option value="<?php echo htmlspecialchars($ocupacion); ?>"><?php echo htmlspecialchars($ocupacion); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Tabla de segmentos de calidad -->
                <div class="card p-6">
                    <h3 class="text-xl font-semibold mb-4">📈 Análisis por Segmentos de Calidad</h3>
                    <div class="overflow-x-auto">
                        <table id="quality-table" class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Calidad Lead</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ingresos</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estudios</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ocupación</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Leads</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ventas</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Conv. %</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ROAS</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ingresos</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gasto</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Profit</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Puntaje Prom.</th>
                                </tr>
                            </thead>
                            <tbody id="quality-tbody" class="bg-white divide-y divide-gray-200">
                                <?php foreach ($quality_data['segments'] as $segment): ?>
                                <tr class="quality-row hover:bg-gray-50" 
                                    data-qlead="<?php echo htmlspecialchars($segment['qlead']); ?>"
                                    data-ingresos="<?php echo htmlspecialchars($segment['ingresos']); ?>"
                                    data-estudios="<?php echo htmlspecialchars($segment['estudios']); ?>"
                                    data-ocupacion="<?php echo htmlspecialchars($segment['ocupacion']); ?>">
                                    
                                    <td class="px-4 py-4 text-sm font-medium text-gray-900">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                            <?php echo $segment['qlead'] == 'Caliente' ? 'bg-red-100 text-red-800' : 
                                                     ($segment['qlead'] == 'Tibio' ? 'bg-yellow-100 text-yellow-800' : 
                                                     ($segment['qlead'] == 'Frio' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800')); ?>">
                                            <?php echo htmlspecialchars($segment['qlead']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($segment['ingresos']); ?></td>
                                    <td class="px-4 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($segment['estudios']); ?></td>
                                    <td class="px-4 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($segment['ocupacion']); ?></td>
                                    <td class="px-4 py-4 text-sm font-semibold text-blue-600"><?php echo number_format($segment['total_leads']); ?></td>
                                    <td class="px-4 py-4 text-sm font-semibold text-purple-600"><?php echo number_format($segment['total_sales']); ?></td>
                                    <td class="px-4 py-4 text-sm font-semibold <?php echo $segment['conversion_rate'] >= 5 ? 'text-green-600' : ($segment['conversion_rate'] >= 2 ? 'text-yellow-600' : 'text-red-600'); ?>">
                                        <?php echo number_format($segment['conversion_rate'], 2); ?>%
                                    </td>
                                    <td class="px-4 py-4 text-sm font-bold <?php echo $segment['roas'] >= 2 ? 'text-green-600' : ($segment['roas'] >= 1 ? 'text-yellow-600' : 'text-red-600'); ?>">
                                        <?php echo number_format($segment['roas'], 2); ?>x
                                    </td>
                                    <td class="px-4 py-4 text-sm font-semibold text-green-600">$<?php echo number_format($segment['total_revenue'], 2); ?></td>
                                    <td class="px-4 py-4 text-sm font-semibold text-red-600">$<?php echo number_format($segment['total_spend'], 2); ?></td>
                                    <td class="px-4 py-4 text-sm font-bold <?php echo $segment['profit'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                        $<?php echo number_format($segment['profit'], 2); ?>
                                    </td>
                                    <td class="px-4 py-4 text-sm font-medium text-indigo-600">
                                        <?php echo number_format($segment['avg_puntaje'], 1); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        </div>
        <?php endif; ?>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Incrustar los datos de PHP en JavaScript
    const analysisData = <?php echo json_encode($interactive_data); ?>;
    const qualityData = <?php echo json_encode($quality_data); ?>;

    if (!analysisData || !analysisData.ads) {
        console.log("No hay datos de análisis para mostrar.");
        return;
    }

    const mainListContainer = document.getElementById('main-list');
    const detailsViewContainer = document.getElementById('details-view');
    const summaryCardsContainer = document.getElementById('summary-cards');

    // Estado de la aplicación
    let selectedKeys = new Set();
    let lastSelectedKey = null;
    let currentPerspective = 'ads'; // 'ads' o 'segments'
    let segmentationData = null;

    // Función para formatear moneda
    const formatCurrency = (value) => {
        if (isNaN(value) || value === null || value === undefined) {
            return '$0.00';
        }
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);
    };
    
    // Función para formatear números compactos (para la tabla de anuncios)
    const formatCompact = (value) => {
        if (isNaN(value) || value === null || value === undefined) {
            return '0';
        }
        if (Math.abs(value) >= 1000) {
            return new Intl.NumberFormat('en-US', { 
                notation: 'compact', 
                maximumFractionDigits: 1 
            }).format(value);
        }
        return Math.round(value).toString();
    };

    // Función para crear datos agrupados por segmentación
    function createSegmentationData() {
        const segments = {};
        
        // Agrupar todos los datos por segmentación
        Object.keys(analysisData.ads).forEach(adKey => {
            const ad = analysisData.ads[adKey];
            
            ad.segmentations.forEach(seg => {
                const segKey = seg.name.toLowerCase().trim();
                
                if (!segments[segKey]) {
                    segments[segKey] = {
                        key: segKey,
                        name: seg.name,
                        campaign_name: seg.campaign_name || '',
                        total_revenue: 0,
                        total_leads: 0,
                        total_sales: 0,
                        total_spend: 0,
                        ads: []
                    };
                }
                
                // Agregar métricas
                segments[segKey].total_revenue += seg.revenue;
                segments[segKey].total_leads += seg.leads;
                segments[segKey].total_sales += seg.sales;
                segments[segKey].total_spend += seg.spend_allocated;
                
                // Añadir información del anuncio
                segments[segKey].ads.push({
                    key: adKey,
                    name: ad.ad_name_display,
                    revenue: seg.revenue,
                    leads: seg.leads,
                    sales: seg.sales,
                    spend: seg.spend_allocated,
                    conversion_rate: seg.conversion_rate,
                    cpl: seg.cpl,
                    profit: seg.profit
                });
            });
        });
        
        // Calcular métricas derivadas para cada segmentación
        Object.keys(segments).forEach(segKey => {
            const seg = segments[segKey];
            seg.conversion_rate = seg.total_leads > 0 ? (seg.total_sales / seg.total_leads) * 100 : 0;
            seg.roas = seg.total_spend > 0 ? seg.total_revenue / seg.total_spend : 0;
            seg.profit = seg.total_revenue - seg.total_spend;
            seg.cpl = seg.total_leads > 0 ? seg.total_spend / seg.total_leads : 0;
            
            // Ordenar anuncios dentro de cada segmentación por beneficio
            seg.ads.sort((a, b) => b.profit - a.profit);
        });
        
        return segments;
    }

    // Función para actualizar la UI basada en la selección
    function updateUIForSelection() {
        // Resaltar filas seleccionadas
        document.querySelectorAll('.main-item').forEach(row => {
            if (selectedKeys.has(row.dataset.itemKey)) {
                row.classList.add('active');
            } else {
                row.classList.remove('active');
            }
        });

        if (selectedKeys.size === 0) {
            // Mostrar estado inicial si no hay selección
            const itemType = currentPerspective === 'ads' ? 'anuncios' : 'segmentaciones';
            detailsViewContainer.innerHTML = `
                <div id="details-placeholder" class="card text-center p-10">
                    <h3 class="text-xl font-semibold text-gray-700">Selecciona uno o más ${itemType}</h3>
                    <p class="text-gray-500">Los detalles de tu selección aparecerán aquí.</p>
                </div>
            `;
            updateSummaryCards(analysisData.summary); // Mostrar resumen general
            return;
        }

        // Calcular datos agregados para la selección
        let totalLeads = 0;
        let totalSales = 0;
        let totalRevenue = 0;
        let totalSpend = 0;
        let combinedDetails = [];

        if (currentPerspective === 'ads') {
            // Perspectiva de anuncios → segmentaciones
            selectedKeys.forEach(key => {
                const ad = analysisData.ads[key];
                totalLeads += ad.total_leads;
                totalSales += ad.total_sales;
                totalRevenue += ad.total_revenue;
                totalSpend += ad.total_spend;
                combinedDetails.push(...ad.segmentations);
            });
        } else {
            // Perspectiva de segmentaciones → anuncios
            selectedKeys.forEach(key => {
                const seg = segmentationData[key];
                totalLeads += seg.total_leads;
                totalSales += seg.total_sales;
                totalRevenue += seg.total_revenue;
                totalSpend += seg.total_spend;
                combinedDetails.push(...seg.ads);
            });
        }

        // Agrupar detalles por nombre
        const groupedDetails = combinedDetails.reduce((acc, detail) => {
            const normalizedName = detail.name.toLowerCase().trim();
            if (!acc[normalizedName]) {
                acc[normalizedName] = { ...detail };
                // Añadir campos específicos según la perspectiva
                if (currentPerspective === 'segments') {
                    acc[normalizedName].spend = detail.spend || 0;
                } else {
                    acc[normalizedName].spend_allocated = detail.spend_allocated || 0;
                }
            } else {
                acc[normalizedName].leads += detail.leads;
                acc[normalizedName].sales += detail.sales;
                acc[normalizedName].revenue += detail.revenue;
                if (currentPerspective === 'segments') {
                    acc[normalizedName].spend += detail.spend || 0;
                } else {
                    acc[normalizedName].spend_allocated += detail.spend_allocated || 0;
                }
                acc[normalizedName].profit += detail.profit;
            }
            return acc;
        }, {});

        Object.values(groupedDetails).forEach(detail => {
            detail.conversion_rate = detail.leads > 0 ? (detail.sales / detail.leads) * 100 : 0;
        });

        // Actualizar tarjetas de resumen
        updateSummaryCards({
            total_revenue: totalRevenue,
            total_spend: totalSpend,
            total_roas: totalSpend > 0 ? totalRevenue / totalSpend : 0
        });

        // Generar el HTML para la vista de detalles
        displayCombinedDetails(Object.values(groupedDetails), { totalLeads, totalSales, totalRevenue, totalSpend });
    }

    // Función para mostrar los detalles combinados de la selección
    function displayCombinedDetails(details, totals) {
        const totalConversionRate = totals.totalLeads > 0 ? (totals.totalSales / totals.totalLeads) * 100 : 0;
        const totalProfit = totals.totalRevenue - totals.totalSpend;
        const itemType = currentPerspective === 'ads' ? 'anuncios' : 'segmentaciones';
        const detailType = currentPerspective === 'ads' ? 'Segmentaciones' : 'Anuncios';
        
        detailsViewContainer.innerHTML = `
            <div class="card p-6">
                <h2 class="text-2xl font-bold text-gray-800">Resumen de la Selección (${selectedKeys.size} ${itemType})</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
                <div class="card p-4 text-center">
                    <h4 class="text-xs font-medium text-gray-500 uppercase">Leads Totales</h4>
                    <p class="text-2xl font-bold text-blue-600">${totals.totalLeads.toLocaleString()}</p>
                </div>
                <div class="card p-4 text-center">
                    <h4 class="text-xs font-medium text-gray-500 uppercase">Ventas Totales</h4>
                    <p class="text-2xl font-bold text-purple-600">${totals.totalSales.toLocaleString()}</p>
                </div>
                <div class="card p-4 text-center">
                    <h4 class="text-xs font-medium text-gray-500 uppercase">Tasa Conversión</h4>
                    <p class="text-2xl font-bold ${totalConversionRate >= 10 ? 'text-green-600' : totalConversionRate >= 5 ? 'text-yellow-600' : 'text-red-600'}">${totalConversionRate.toFixed(2)}%</p>
                </div>
                <div class="card p-4 text-center">
                    <h4 class="text-xs font-medium text-gray-500 uppercase">Ingresos</h4>
                    <p class="text-2xl font-bold text-green-600">${formatCurrency(totals.totalRevenue)}</p>
                </div>
                <div class="card p-4 text-center">
                    <h4 class="text-xs font-medium text-gray-500 uppercase">Gasto</h4>
                    <p class="text-2xl font-bold text-red-600">${formatCurrency(totals.totalSpend)}</p>
                </div>
                <div class="card p-4 text-center">
                    <h4 class="text-xs font-medium text-gray-500 uppercase">Utilidad</h4>
                    <p class="text-2xl font-bold ${totalProfit >= 0 ? 'text-green-600' : 'text-red-600'}">${formatCurrency(totalProfit)}</p>

                </div>
            </div>
            <div class="card p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-semibold">Análisis Combinado de ${detailType}</h3>
                    <div class="flex items-center gap-2">
                        <label for="segSort" class="text-sm font-medium text-gray-700">Ordenar por:</label>
                        <select id="segSort" class="text-sm border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            <option value="profit">Beneficio/Pérdida</option>
                            <option value="revenue">Ingresos</option>
                            <option value="leads">Leads</option>
                            <option value="sales">Ventas</option>
                            <option value="conversion_rate">Tasa Conversión</option>
                            <option value="spend_allocated">Gasto Asignado</option>
                            <option value="roas">ROAS</option>
                            <option value="cpl">Costo por Lead</option>
                            <option value="name">Nombre</option>
                        </select>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortSegmentations('name')">
                                    Segmentación <span class="ml-1">↕️</span>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortSegmentations('leads')">
                                    Leads <span class="ml-1">↕️</span>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortSegmentations('sales')">
                                    Ventas <span class="ml-1">↕️</span>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortSegmentations('roas')">
                                    ROAS <span class="ml-1">↕️</span>
                                </th>
                                
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortSegmentations('revenue')">
                                    Ingresos <span class="ml-1">↕️</span>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortSegmentations('spend_allocated')">
                                    Gasto Asignado <span class="ml-1">↕️</span>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortSegmentations('profit')">
                                    Beneficio/Pérdida <span class="ml-1">↕️</span>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortSegmentations('conversion_rate')">
                                    Tasa Conversión <span class="ml-1">↕️</span>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortSegmentations('cpl')">
                                    CPL <span class="ml-1">↕️</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody id="segmentationsTableBody" class="bg-white divide-y divide-gray-200">
                            <!-- Contenido generado dinámicamente -->
                        </tbody>
                    </table>
                </div>
            </div>
        `;
        
        renderDetailsTable(details);
        sortDetailsBy('profit'); // Orden inicial
        document.getElementById('segSort').addEventListener('change', (e) => {
            sortDetailsBy(e.target.value);
        });
    }

    // Función para actualizar las tarjetas de resumen
    function updateSummaryCards(summaryData) {
        summaryCardsContainer.innerHTML = `
            <div class="card p-4 text-center">
                <h4 class="text-sm font-medium text-gray-500 uppercase">Ingresos ${selectedKeys.size > 0 ? 'Seleccionados' : 'Totales'}</h4>
                <p class="text-3xl font-bold text-green-600">${formatCurrency(summaryData.total_revenue)}</p>
                 ${(selectedKeys.size === 0 && analysisData.summary.multiply_revenue) ? '<p class="text-xs text-gray-500 mt-1">Coproducción Total (x2)</p>' : ''}
            </div>
            <div class="card p-4 text-center">
                <h4 class="text-sm font-medium text-gray-500 uppercase">Gasto ${selectedKeys.size > 0 ? 'Seleccionado' : 'Total'}</h4>
                <p class="text-3xl font-bold text-red-600">${formatCurrency(summaryData.total_spend)}</p>
            </div>
            <div class="card p-4 text-center">
                <h4 class="text-sm font-medium text-gray-500 uppercase">ROAS ${selectedKeys.size > 0 ? 'de Selección' : 'General'}</h4>
                <p class="text-3xl font-bold text-indigo-600">${summaryData.total_roas.toFixed(2)}x</p>
                 ${(selectedKeys.size === 0 && analysisData.summary.multiply_revenue) ? '<p class="text-xs text-gray-500 mt-1">Basado en ingresos totales</p>' : ''}
            </div>
        `;
    }

    // Variables globales para manejo de detalles
    let currentDetails = [];
    let detailSortOrder = {};

    // Función para renderizar tabla de detalles
    function renderDetailsTable(details) {
        currentDetails = [...details];
        const tbody = document.getElementById('segmentationsTableBody');
        
        tbody.innerHTML = currentDetails.map(detail => {
            const spendKey = currentPerspective === 'segments' ? 'spend' : 'spend_allocated';
            const detailSpend = detail[spendKey] || 0;
            const detailRevenue = detail.revenue || 0;
            const detailRoas = detailSpend > 0 ? detailRevenue / detailSpend : 0;
            const roasColor = detailRoas >= 2 ? 'text-green-600' : detailRoas >= 1 ? 'text-yellow-600' : 'text-red-600';
            
            return `
            <tr>
                <td class="px-4 py-4 text-sm text-gray-900 max-w-xs break-words">
                    <div class="font-medium">${detail.name}</div>
                </td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">${(detail.leads || 0).toLocaleString()}</td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">${(detail.sales || 0).toLocaleString()}</td>
                <td class="px-4 py-4 whitespace-nowrap text-sm ${roasColor} font-bold">${detailRoas.toFixed(2)}x</td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-green-600 font-semibold">${formatCurrency(detailRevenue)}</td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-red-600 font-semibold">${formatCurrency(detailSpend)}</td>
                <td class="px-4 py-4 whitespace-nowrap text-sm ${(detail.profit || 0) >= 0 ? 'text-green-600' : 'text-red-600'} font-bold">${formatCurrency(detail.profit || 0)}</td>
                <td class="px-4 py-4 whitespace-nowrap text-sm ${(detail.conversion_rate || 0) >= 10 ? 'text-green-600' : (detail.conversion_rate || 0) >= 5 ? 'text-yellow-600' : 'text-red-600'} font-semibold">${(detail.conversion_rate || 0).toFixed(2)}%</td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-blue-600 font-semibold">${formatCurrency(detail.cpl || 0)}</td>
            </tr>
            `;
        }).join('');
    }

    // Función para ordenar detalles por criterio
    function sortDetailsBy(criteria) {
        const sortMap = {
            'revenue': (a, b) => b.revenue - a.revenue,
            'profit': (a, b) => b.profit - a.profit,
            'leads': (a, b) => b.leads - a.leads,
            'sales': (a, b) => b.sales - a.sales,
            'conversion_rate': (a, b) => b.conversion_rate - a.conversion_rate,
            'spend_allocated': (a, b) => {
                const spendKey = currentPerspective === 'segments' ? 'spend' : 'spend_allocated';
                return (b[spendKey] || 0) - (a[spendKey] || 0);
            },
            'roas': (a, b) => {
                const spendKey = currentPerspective === 'segments' ? 'spend' : 'spend_allocated';
                const roasA = (a[spendKey] || 0) > 0 ? a.revenue / (a[spendKey] || 0) : 0;
                const roasB = (b[spendKey] || 0) > 0 ? b.revenue / (b[spendKey] || 0) : 0;
                return roasB - roasA;
            },
            'cpl': (a, b) => a.cpl - b.cpl, // CPL más bajo es mejor
            'name': (a, b) => a.name.localeCompare(b.name)
        };
        
        currentDetails.sort(sortMap[criteria] || sortMap['revenue']);
        renderDetailsTable(currentDetails);
    }

    // Función global para ordenar detalles (llamada desde onclick)
    window.sortSegmentations = function(criteria) {
        // Toggle sort order
        detailSortOrder[criteria] = !detailSortOrder[criteria];
        const ascending = detailSortOrder[criteria];
        
        const sortMap = {
            'revenue': ascending ? (a, b) => a.revenue - b.revenue : (a, b) => b.revenue - a.revenue,
            'profit': ascending ? (a, b) => a.profit - b.profit : (a, b) => b.profit - a.profit,
            'leads': ascending ? (a, b) => a.leads - b.leads : (a, b) => b.leads - a.leads,
            'sales': ascending ? (a, b) => a.sales - b.sales : (a, b) => b.sales - a.sales,
            'conversion_rate': ascending ? (a, b) => a.conversion_rate - b.conversion_rate : (a, b) => b.conversion_rate - a.conversion_rate,
            'spend_allocated': ascending ? (a, b) => {
                const spendKey = currentPerspective === 'segments' ? 'spend' : 'spend_allocated';
                return (a[spendKey] || 0) - (b[spendKey] || 0);
            } : (a, b) => {
                const spendKey = currentPerspective === 'segments' ? 'spend' : 'spend_allocated';
                return (b[spendKey] || 0) - (a[spendKey] || 0);
            },
            'roas': ascending ? (a, b) => {
                const spendKey = currentPerspective === 'segments' ? 'spend' : 'spend_allocated';
                const roasA = (a[spendKey] || 0) > 0 ? a.revenue / (a[spendKey] || 0) : 0;
                const roasB = (b[spendKey] || 0) > 0 ? b.revenue / (b[spendKey] || 0) : 0;
                return roasA - roasB;
            } : (a, b) => {
                const spendKey = currentPerspective === 'segments' ? 'spend' : 'spend_allocated';
                const roasA = (a[spendKey] || 0) > 0 ? a.revenue / (a[spendKey] || 0) : 0;
                const roasB = (b[spendKey] || 0) > 0 ? b.revenue / (b[spendKey] || 0) : 0;
                return roasB - roasA;
            },
            'cpl': ascending ? (a, b) => a.cpl - b.cpl : (a, b) => b.cpl - a.cpl, // CPL más bajo es mejor
            'name': ascending ? (a, b) => a.name.localeCompare(b.name) : (a, b) => b.name.localeCompare(a.name)
        };
        
        currentDetails.sort(sortMap[criteria]);
        renderDetailsTable(currentDetails);
        
        // Update dropdown to reflect sort
        document.getElementById('segSort').value = criteria;
    }

    // Datos para las listas principales
    let adsArray = Object.keys(analysisData.ads).map(adKey => ({
        key: adKey,
        ...analysisData.ads[adKey]
    }));
    
    // Función para cambiar perspectiva
    function switchPerspective(isSegmentsPerspective) {
        currentPerspective = isSegmentsPerspective ? 'segments' : 'ads';
        selectedKeys.clear();
        
        // Actualizar UI
        const title = currentPerspective === 'ads' ? 'Análisis por Anuncio' : 'Análisis por Segmentación';
        const columnName = currentPerspective === 'ads' ? 'Anuncio' : 'Segmentación';
        
        document.getElementById('main-table-title').textContent = title;
        document.getElementById('main-column-name').textContent = columnName;
        
        if (currentPerspective === 'segments' && !segmentationData) {
            segmentationData = createSegmentationData();
        }
        
        renderMainList();
        updateUIForSelection();
    }

    // Función genérica para renderizar la lista principal
    function renderMainList() {
        if (currentPerspective === 'ads') {
            renderAdsList();
        } else {
            renderSegmentsList();
        }
    }

    function sortMainList(criteria) {
        if (currentPerspective === 'ads') {
            sortAds(criteria);
        } else {
            sortSegments(criteria);
        }
    }

    function sortAds(criteria) {
        const sortMap = {
            'profit': (a, b) => b.profit - a.profit,
            'revenue': (a, b) => b.total_revenue - a.total_revenue,
                            'spend': (a, b) => b.total_spend - a.total_spend,
            'roas': (a, b) => b.roas - a.roas,
            'name': (a, b) => a.ad_name_display.localeCompare(b.ad_name_display)
        };
        adsArray.sort(sortMap[criteria] || sortMap['profit']);
        renderAdsList();
    }

    function renderAdsList() {
        const currentActiveKey = document.querySelector('.main-item.active')?.dataset?.itemKey;
        
        mainListContainer.innerHTML = adsArray.map(ad => {
            const profitColor = ad.profit >= 0 ? 'text-green-600' : 'text-red-600';
            const isActive = currentActiveKey === ad.key ? 'bg-indigo-100 active' : '';
            
        return `
                <tr class="main-item cursor-pointer hover:bg-gray-50 ${isActive}" data-item-key="${ad.key}">
                    <td class="px-2 py-2">
                        <div class="font-medium text-gray-900 text-xs leading-tight break-words" title="${ad.ad_name_display}">
                            ${ad.ad_name_display}
                        </div>
                    </td>
                    <td class="px-1 py-2 text-right text-green-600 font-medium">
                        $${formatCompact(ad.total_revenue)}
                    </td>
                    <td class="px-1 py-2 text-right text-red-600 font-medium">
                        $${formatCompact(ad.total_spend)}
                    </td>
                    <td class="px-1 py-2 text-right ${profitColor} font-bold">
                        $${formatCompact(ad.profit)}
                    </td>
                    <td class="px-1 py-2 text-right text-indigo-600 font-medium">
                        ${ad.roas.toFixed(1)}x
                    </td>
                </tr>
        `;
    }).join('');
    }

    // Nueva función para renderizar lista de segmentaciones
    function renderSegmentsList() {
        let segmentsArray = Object.values(segmentationData);
        
        mainListContainer.innerHTML = segmentsArray.map(seg => {
            const profitColor = seg.profit >= 0 ? 'text-green-600' : 'text-red-600';
            
            return `
                <tr class="main-item cursor-pointer hover:bg-gray-50" data-item-key="${seg.key}">
                    <td class="px-2 py-2">
                        <div class="font-medium text-gray-900 text-xs leading-tight break-words" title="${seg.name}">
                            ${seg.name}
                        </div>
                    </td>
                    <td class="px-1 py-2 text-right text-green-600 font-medium">
                        $${formatCompact(seg.total_revenue)}
                    </td>
                    <td class="px-1 py-2 text-right text-red-600 font-medium">
                        $${formatCompact(seg.total_spend)}
                    </td>
                    <td class="px-1 py-2 text-right ${profitColor} font-bold">
                        $${formatCompact(seg.profit)}
                    </td>
                    <td class="px-1 py-2 text-right text-indigo-600 font-medium">
                        ${seg.roas.toFixed(1)}x
                    </td>
                </tr>
            `;
        }).join('');
    }

    // Nueva función para ordenar segmentaciones
    function sortSegments(criteria) {
        const sortMap = {
            'profit': (a, b) => b.profit - a.profit,
            'revenue': (a, b) => b.total_revenue - a.total_revenue,
            'spend': (a, b) => b.total_spend - a.total_spend,
            'roas': (a, b) => b.roas - a.roas,
            'name': (a, b) => a.name.localeCompare(b.name)
        };
        
        let segmentsArray = Object.values(segmentationData);
        segmentsArray.sort(sortMap[criteria] || sortMap['profit']);
        
        // Recrear el objeto ordenado
        segmentationData = {};
        segmentsArray.forEach(seg => {
            segmentationData[seg.key] = seg;
        });
        
        renderSegmentsList();
    }

    // Inicializar con ordenamiento por utilidad
    sortAds('profit');

    // Añadir event listeners
    function addEventListeners() {
        document.querySelectorAll('.main-item').forEach((row, index) => {
            row.addEventListener('click', (e) => {
                const itemKey = row.dataset.itemKey;
                
                if (e.shiftKey && lastSelectedKey) {
                    // Selección con Shift
                    selectedKeys.clear();
                    const currentArray = currentPerspective === 'ads' ? adsArray : Object.values(segmentationData);
                    const lastIndex = currentArray.findIndex(item => item.key === lastSelectedKey);
                    const currentIndex = currentArray.findIndex(item => item.key === itemKey);
                    const [start, end] = [lastIndex, currentIndex].sort((a,b) => a-b);
                    for (let i = start; i <= end; i++) {
                        selectedKeys.add(currentArray[i].key);
                    }
                } else if (e.ctrlKey || e.metaKey) {
                    // Selección con Ctrl/Cmd
                    if (selectedKeys.has(itemKey)) {
                        selectedKeys.delete(itemKey);
                    } else {
                        selectedKeys.add(itemKey);
                    }
                } else {
                    // Selección simple
                    selectedKeys.clear();
                    selectedKeys.add(itemKey);
                }
                
                lastSelectedKey = itemKey;
                updateUIForSelection();
            });
        });

        // Event listeners para los botones de selección
        document.getElementById('selectAllBtn').addEventListener('click', () => {
            selectedKeys.clear();
            if (currentPerspective === 'ads') {
                adsArray.forEach(ad => selectedKeys.add(ad.key));
            } else {
                Object.keys(segmentationData).forEach(key => selectedKeys.add(key));
            }
            updateUIForSelection();
        });

        document.getElementById('selectNoneBtn').addEventListener('click', () => {
            selectedKeys.clear();
            updateUIForSelection();
        });
    }

    // Event listener para el selector de ordenamiento
    document.getElementById('sortBy').addEventListener('change', (e) => {
        sortMainList(e.target.value);
        addEventListeners();
    });

    // Event listener para el toggle de perspectiva
    document.getElementById('perspective-toggle').addEventListener('change', (e) => {
        switchPerspective(e.target.checked);
        addEventListeners();
    });

    // Inicializar event listeners
    addEventListeners();

    // Inicializar con todos los anuncios seleccionados
    adsArray.forEach(ad => selectedKeys.add(ad.key));
    updateUIForSelection();

    // === FUNCIONALIDAD DE PESTAÑAS ===
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');

    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetTab = this.id.replace('tab-', '');
            
            // Actualizar botones
            tabButtons.forEach(btn => {
                btn.classList.remove('active', 'border-indigo-500', 'text-indigo-600');
                btn.classList.add('border-transparent', 'text-gray-500');
            });
            
            this.classList.add('active', 'border-indigo-500', 'text-indigo-600');
            this.classList.remove('border-transparent', 'text-gray-500');
            
            // Mostrar/ocultar contenido
            tabContents.forEach(content => {
                content.classList.add('hidden');
            });
            
            document.getElementById(`content-${targetTab}`).classList.remove('hidden');
        });
    });

    // === FUNCIONALIDAD DE FILTROS PARA CALIDAD ===
    if (qualityData && qualityData.segments) {
        const filterElements = {
            qlead: document.getElementById('filter-qlead'),
            ingresos: document.getElementById('filter-ingresos'),
            estudios: document.getElementById('filter-estudios'),
            ocupacion: document.getElementById('filter-ocupacion')
        };

        function applyQualityFilters() {
            const filters = {
                qlead: filterElements.qlead?.value || '',
                ingresos: filterElements.ingresos?.value || '',
                estudios: filterElements.estudios?.value || '',
                ocupacion: filterElements.ocupacion?.value || ''
            };

            const rows = document.querySelectorAll('.quality-row');
            rows.forEach(row => {
                let shouldShow = true;
                
                Object.keys(filters).forEach(filterType => {
                    if (filters[filterType] && row.dataset[filterType] !== filters[filterType]) {
                        shouldShow = false;
                    }
                });
                
                if (shouldShow) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            // Actualizar contadores
            const visibleRows = document.querySelectorAll('.quality-row:not([style*="display: none"])');
            let totalLeads = 0, totalSales = 0, totalRevenue = 0, totalSpend = 0;
            
            visibleRows.forEach(row => {
                const cells = row.querySelectorAll('td');
                totalLeads += parseFloat(cells[4].textContent.replace(/,/g, '')) || 0;
                totalSales += parseFloat(cells[5].textContent.replace(/,/g, '')) || 0;
                totalRevenue += parseFloat(cells[8].textContent.replace(/[\$,]/g, '')) || 0;
                totalSpend += parseFloat(cells[9].textContent.replace(/[\$,]/g, '')) || 0;
            });

            // Actualizar tarjetas de resumen (si es necesario)
            console.log(`Filtros aplicados: ${visibleRows.length} segmentos visibles`);
        }

        // Añadir event listeners a los filtros
        Object.values(filterElements).forEach(element => {
            if (element) {
                element.addEventListener('change', applyQualityFilters);
            }
        });
    }
});
</script>

</body>
</html>
