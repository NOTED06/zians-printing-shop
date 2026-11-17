<?php
require_once __DIR__ . '/../session_bootstrap.php';
require_once '../db_conn.php';
require_once 'check_admin.php';

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $exportType = $_GET['type'] ?? 'all'; 
    $exportDate = $_GET['date'] ?? date('Y-m-d');

    
    switch ($exportType) {
        case 'all':
            $dateLabel = 'All dates';
            $dateWhere = '1=1';
            $fileLabel = 'all';
            break;
        case 'day':
            $dateLabel = date('F d, Y', strtotime($exportDate));
            $dateWhere = "DATE(co.date_created) = '" . $conn->real_escape_string($exportDate) . "'";
            $groupBy = '';
            $fileLabel = date('Y-m-d', strtotime($exportDate));
            break;
        case 'month':
            $ym = date('Y-m', strtotime($exportDate . '-01'));
            $dateLabel = date('F Y', strtotime($exportDate . '-01'));
            $dateWhere = "DATE_FORMAT(co.date_created,'%Y-%m') = '" . $conn->real_escape_string($ym) . "'";
            $groupBy = '';
            $fileLabel = $ym;
            break;
        case 'year':
            $y = date('Y', strtotime($exportDate . '-01-01'));
            $dateLabel = 'Year ' . $y;
            $dateWhere = "YEAR(co.date_created) = " . (int)$y;
            $groupBy = '';
            $fileLabel = $y;
            break;
        default:
            $dateLabel = date('F d, Y');
            $dateWhere = "DATE(co.date_created) = CURDATE()";
            $fileLabel = date('Y-m-d');
    }

    
    $exportRes = $conn->query("
        SELECT co.id, co.product_name, co.variant_name, co.size, co.quantity,
               co.price, (co.price * co.quantity) as line_total,
               co.status, co.payment_mode, co.gcash_reference, co.down_payment_amount,
               co.order_notes, co.date_created, c.username as customer_name, c.email as customer_email
        FROM orders co
        LEFT JOIN customers c ON co.customer_id = c.customer_id
        WHERE $dateWhere
        ORDER BY co.date_created ASC
    ");
    $exportRows = [];
    if ($exportRes) while ($r = $exportRes->fetch_assoc()) $exportRows[] = $r;

    
    $expTotalOrders = count($exportRows);
    $expCompleted   = count(array_filter($exportRows, fn($r) => $r['status'] === 'Completed'));
    $expTotalRev    = array_sum(array_map(fn($r) => $r['status'] === 'Completed' ? (float)$r['line_total'] : 0, $exportRows));
    $expTotalQty    = array_sum(array_column($exportRows, 'quantity'));
    $expTotalDown   = array_sum(array_map(fn($r) => (float)$r['down_payment_amount'], $exportRows));

    
    $filename = "Zians_PrintShop_Sales_{$exportType}_{$fileLabel}.xls";
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
<Styles>
 <Style ss:ID="Default"><Font ss:FontName="Calibri" ss:Size="11"/></Style>
 <Style ss:ID="title"><Font ss:FontName="Calibri" ss:Size="16" ss:Bold="1" ss:Color="#1F2937"/></Style>
 <Style ss:ID="subtitle"><Font ss:FontName="Calibri" ss:Size="11" ss:Color="#6B7280"/></Style>
 <Style ss:ID="header"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#FF6B35" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E55A2B"/></Borders></Style>
 <Style ss:ID="cell"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#374151"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#F3F4F6"/></Borders></Style>
 <Style ss:ID="cellAlt"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#374151"/><Interior ss:Color="#FFF7ED" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#F3F4F6"/></Borders></Style>
 <Style ss:ID="currency"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#374151"/><NumberFormat ss:Format="₱#,##0.00"/><Alignment ss:Horizontal="Right"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#F3F4F6"/></Borders></Style>
 <Style ss:ID="currencyAlt"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#374151"/><Interior ss:Color="#FFF7ED" ss:Pattern="Solid"/><NumberFormat ss:Format="₱#,##0.00"/><Alignment ss:Horizontal="Right"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#F3F4F6"/></Borders></Style>
 <Style ss:ID="number"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#374151"/><Alignment ss:Horizontal="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#F3F4F6"/></Borders></Style>
 <Style ss:ID="numberAlt"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#374151"/><Interior ss:Color="#FFF7ED" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#F3F4F6"/></Borders></Style>
 <Style ss:ID="completed"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#22C55E"/><Alignment ss:Horizontal="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#F3F4F6"/></Borders></Style>
 <Style ss:ID="cancelled"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#EF4444"/><Alignment ss:Horizontal="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#F3F4F6"/></Borders></Style>
 <Style ss:ID="pending"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#F59E0B"/><Alignment ss:Horizontal="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#F3F4F6"/></Borders></Style>
 <Style ss:ID="sumLabel"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#1F2937"/><Interior ss:Color="#F9FAFB" ss:Pattern="Solid"/><Borders><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#FF6B35"/><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#FF6B35"/></Borders></Style>
 <Style ss:ID="sumVal"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#FF6B35"/><Interior ss:Color="#F9FAFB" ss:Pattern="Solid"/><NumberFormat ss:Format="₱#,##0.00"/><Alignment ss:Horizontal="Right"/><Borders><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#FF6B35"/><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#FF6B35"/></Borders></Style>
 <Style ss:ID="sumNum"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#FF6B35"/><Interior ss:Color="#F9FAFB" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/><Borders><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#FF6B35"/><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#FF6B35"/></Borders></Style>
 <Style ss:ID="info"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#9CA3AF" ss:Italic="1"/></Style>
</Styles>
<Worksheet ss:Name="Sales Report">
 <Table>
  <Column ss:Width="65"/>
  <Column ss:Width="140"/>
  <Column ss:Width="130"/>
  <Column ss:Width="160"/>
  <Column ss:Width="100"/>
  <Column ss:Width="80"/>
  <Column ss:Width="55"/>
  <Column ss:Width="90"/>
  <Column ss:Width="90"/>
  <Column ss:Width="100"/>
  <Column ss:Width="100"/>
  <Column ss:Width="85"/>
  <Column ss:Width="130"/>
  <Column ss:Width="150"/>
  
  <Row ss:Height="28">
   <Cell ss:StyleID="title" ss:MergeAcross="6"><Data ss:Type="String">ZIAN'S PRINTSHOP — SALES REPORT</Data></Cell>
  </Row>
  <Row ss:Height="20">
   <Cell ss:StyleID="subtitle" ss:MergeAcross="6"><Data ss:Type="String">Period: <?= htmlspecialchars($dateLabel) ?>  |  Exported: <?= date('M d, Y h:i A') ?>  |  Filter: <?= ucfirst($exportType) ?></Data></Cell>
  </Row>
  <Row ss:Height="8"/>
  
  <Row ss:Height="28">
   <Cell ss:StyleID="header"><Data ss:Type="String">Order ID</Data></Cell>
   <Cell ss:StyleID="header"><Data ss:Type="String">Customer</Data></Cell>
   <Cell ss:StyleID="header"><Data ss:Type="String">Email</Data></Cell>
   <Cell ss:StyleID="header"><Data ss:Type="String">Product</Data></Cell>
   <Cell ss:StyleID="header"><Data ss:Type="String">Variant</Data></Cell>
   <Cell ss:StyleID="header"><Data ss:Type="String">Size</Data></Cell>
   <Cell ss:StyleID="header"><Data ss:Type="String">Qty</Data></Cell>
   <Cell ss:StyleID="header"><Data ss:Type="String">Unit Price</Data></Cell>
   <Cell ss:StyleID="header"><Data ss:Type="String">Line Total</Data></Cell>
   <Cell ss:StyleID="header"><Data ss:Type="String">Status</Data></Cell>
   <Cell ss:StyleID="header"><Data ss:Type="String">Payment</Data></Cell>
   <Cell ss:StyleID="header"><Data ss:Type="String">Down Pmt</Data></Cell>
   <Cell ss:StyleID="header"><Data ss:Type="String">GCash Ref</Data></Cell>
   <Cell ss:StyleID="header"><Data ss:Type="String">Date</Data></Cell>
  </Row>
  <?php if (empty($exportRows)): ?>
  <Row><Cell ss:StyleID="cell" ss:MergeAcross="13"><Data ss:Type="String">No orders found for this period.</Data></Cell></Row>
  <?php else: ?>
  <?php foreach ($exportRows as $i => $r):
      $isAlt = $i % 2 === 1;
      $cs = $isAlt ? 'cellAlt' : 'cell';
      $cr = $isAlt ? 'currencyAlt' : 'currency';
      $cn = $isAlt ? 'numberAlt' : 'number';
      $statusStyle = 'pending';
      if ($r['status'] === 'Completed') $statusStyle = 'completed';
      elseif ($r['status'] === 'Cancelled') $statusStyle = 'cancelled';
  ?>
  <Row>
   <Cell ss:StyleID="<?= $cs ?>"><Data ss:Type="String">ORD-<?= str_pad($r['id'], 3, '0', STR_PAD_LEFT) ?></Data></Cell>
   <Cell ss:StyleID="<?= $cs ?>"><Data ss:Type="String"><?= htmlspecialchars($r['customer_name'] ?? 'Unknown') ?></Data></Cell>
   <Cell ss:StyleID="<?= $cs ?>"><Data ss:Type="String"><?= htmlspecialchars($r['customer_email'] ?? '') ?></Data></Cell>
   <Cell ss:StyleID="<?= $cs ?>"><Data ss:Type="String"><?= htmlspecialchars($r['product_name']) ?></Data></Cell>
   <Cell ss:StyleID="<?= $cs ?>"><Data ss:Type="String"><?= htmlspecialchars($r['variant_name'] ?? '—') ?></Data></Cell>
   <Cell ss:StyleID="<?= $cs ?>"><Data ss:Type="String"><?= htmlspecialchars($r['size'] ?? '—') ?></Data></Cell>
   <Cell ss:StyleID="<?= $cn ?>"><Data ss:Type="Number"><?= (int)$r['quantity'] ?></Data></Cell>
   <Cell ss:StyleID="<?= $cr ?>"><Data ss:Type="Number"><?= (float)$r['price'] ?></Data></Cell>
   <Cell ss:StyleID="<?= $cr ?>"><Data ss:Type="Number"><?= (float)$r['line_total'] ?></Data></Cell>
   <Cell ss:StyleID="<?= $statusStyle ?>"><Data ss:Type="String"><?= htmlspecialchars($r['status']) ?></Data></Cell>
   <Cell ss:StyleID="<?= $cs ?>"><Data ss:Type="String"><?= htmlspecialchars($r['payment_mode'] ?? '—') ?></Data></Cell>
   <Cell ss:StyleID="<?= $cr ?>"><Data ss:Type="Number"><?= (float)$r['down_payment_amount'] ?></Data></Cell>
   <Cell ss:StyleID="<?= $cs ?>"><Data ss:Type="String"><?= htmlspecialchars($r['gcash_reference'] ?? '—') ?></Data></Cell>
   <Cell ss:StyleID="<?= $cs ?>"><Data ss:Type="String"><?= date('M d, Y h:i A', strtotime($r['date_created'])) ?></Data></Cell>
  </Row>
  <?php endforeach; ?>
  <?php endif; ?>
  
  <Row ss:Height="6"/>
  
  <Row ss:Height="26">
   <Cell ss:StyleID="sumLabel" ss:MergeAcross="5"><Data ss:Type="String">TOTALS</Data></Cell>
   <Cell ss:StyleID="sumNum"><Data ss:Type="Number"><?= $expTotalQty ?></Data></Cell>
   <Cell ss:StyleID="sumLabel"><Data ss:Type="String"></Data></Cell>
   <Cell ss:StyleID="sumVal"><Data ss:Type="Number"><?= $expTotalRev ?></Data></Cell>
   <Cell ss:StyleID="sumNum"><Data ss:Type="String"><?= $expCompleted ?>/<?= $expTotalOrders ?> done</Data></Cell>
   <Cell ss:StyleID="sumLabel"><Data ss:Type="String"></Data></Cell>
   <Cell ss:StyleID="sumVal"><Data ss:Type="Number"><?= $expTotalDown ?></Data></Cell>
   <Cell ss:StyleID="sumLabel" ss:MergeAcross="1"><Data ss:Type="String"></Data></Cell>
  </Row>
  <Row ss:Height="6"/>
  <Row>
   <Cell ss:StyleID="info" ss:MergeAcross="6"><Data ss:Type="String">Generated by Zian's PrintShop Admin — <?= date('M d, Y h:i A') ?></Data></Cell>
  </Row>
 </Table>
</Worksheet>
</Workbook>
<?php
    exit;
}

$totalRev = $conn->query("SELECT COALESCE(SUM(price*quantity),0) as rev FROM orders WHERE status='Completed'")->fetch_assoc()['rev'];
$totalOrders = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status='Completed'")->fetch_assoc()['c'];
$pendingOrders = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status IN ('In Cart','Processing','Production','Ready for Pickup')")->fetch_assoc()['c'];
$activeCustomers = $conn->query("SELECT COUNT(DISTINCT customer_id) as c FROM orders")->fetch_assoc()['c'];

$thisMonth = date('Y-m');
$monthRev = $conn->query("SELECT COALESCE(SUM(price*quantity),0) as rev FROM orders WHERE status='Completed' AND DATE_FORMAT(date_created,'%Y-%m')='$thisMonth'")->fetch_assoc()['rev'];

$lastMonth = date('Y-m', strtotime('-1 month'));
$lastMonthRev = $conn->query("SELECT COALESCE(SUM(price*quantity),0) as rev FROM orders WHERE status='Completed' AND DATE_FORMAT(date_created,'%Y-%m')='$lastMonth'")->fetch_assoc()['rev'];

$monthlyData = [];
$monthlyRes = $conn->query("
    SELECT DATE_FORMAT(date_created,'%Y-%m') as mon,
           DATE_FORMAT(date_created,'%b %Y') as label,
           COALESCE(SUM(price*quantity),0) as rev,
           COUNT(*) as orders
    FROM orders
    WHERE status='Completed' AND date_created >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(date_created,'%Y-%m')
    ORDER BY mon ASC
");
if ($monthlyRes) while ($row = $monthlyRes->fetch_assoc()) $monthlyData[] = $row;

$weeklyData = [];
$weeklyRes = $conn->query("
    SELECT YEARWEEK(date_created,1) as ywk,
           DATE_FORMAT(MIN(date_created), 'Wk %u') as label,
           COALESCE(SUM(price*quantity),0) as rev,
           COUNT(*) as orders
    FROM orders
    WHERE status='Completed' AND date_created >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
    GROUP BY YEARWEEK(date_created,1)
    ORDER BY ywk ASC
");
if ($weeklyRes) while ($row = $weeklyRes->fetch_assoc()) $weeklyData[] = $row;

$topProducts = [];
$topRes = $conn->query("
    SELECT co.product_name,
           SUM(co.quantity) as total_qty,
           COALESCE(SUM(co.price * co.quantity),0) as total_rev,
           COUNT(co.id) as total_orders
    FROM orders co
    WHERE co.status = 'Completed'
    GROUP BY co.product_name
    ORDER BY total_rev DESC
    LIMIT 10
");
if ($topRes) while ($row = $topRes->fetch_assoc()) $topProducts[] = $row;

$pipelineRes = $conn->query("
    SELECT status, COUNT(*) as cnt, COALESCE(SUM(price*quantity),0) as rev
    FROM orders
    GROUP BY status
    ORDER BY FIELD(status,'In Cart','Processing','Production','Ready for Pickup','Completed','Cancelled')
");
$pipeline = [];
if ($pipelineRes) while ($row = $pipelineRes->fetch_assoc()) $pipeline[] = $row;

$recentSalesRes = $conn->query("
    SELECT co.id, co.product_name, co.variant_name, co.quantity, co.price,
           co.date_created, c.username as customer_name
    FROM orders co
    LEFT JOIN customers c ON co.customer_id = c.customer_id
    WHERE co.status = 'Completed'
    ORDER BY co.date_created DESC
    LIMIT 15
");
$recentSales = [];
if ($recentSalesRes) while ($row = $recentSalesRes->fetch_assoc()) $recentSales[] = $row;

$maxMonthly = max(1, max(array_column($monthlyData, 'rev') ?: [0]));
$maxProduct = max(1, max(array_column($topProducts, 'total_rev') ?: [0]));

$growth = $lastMonthRev > 0 ? round((($monthRev - $lastMonthRev) / $lastMonthRev) * 100, 1) : null;

$gcashOrderStats = $conn->query("SELECT
    COALESCE(SUM(down_payment_amount), 0) AS total,
    COUNT(DISTINCT CASE
        WHEN batch_id IS NULL OR batch_id = '' THEN CONCAT('order-', id)
        ELSE CONCAT('batch-', batch_id)
    END) AS payment_count
    FROM orders
    WHERE payment_mode = 'GCash'
      AND down_payment_amount > 0
      AND status <> 'Cancelled'")->fetch_assoc();
$gcashOrderTotal = (float)($gcashOrderStats['total'] ?? 0);
$gcashOrderCount = (int)($gcashOrderStats['payment_count'] ?? 0);

$gcashMonthRev = $conn->query("SELECT COALESCE(SUM(CASE WHEN payment_type='down' THEN down_payment ELSE full_price END),0) as rev FROM messages WHERE record_type='chat' AND payment_status='paid' AND DATE_FORMAT(paid_at,'%Y-%m')='$thisMonth'")->fetch_assoc()['rev'];

$recentGcashRes = $conn->query("
    SELECT m.*, m.payment_product_name AS product_name, c.username as customer_name
    FROM messages m
    JOIN customers c ON c.customer_id = m.customer_id
    WHERE m.record_type='chat' AND m.payment_status = 'paid'
    ORDER BY m.paid_at DESC
    LIMIT 10
");
$recentGcash = [];
if ($recentGcashRes) while ($row = $recentGcashRes->fetch_assoc()) $recentGcash[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report - Zian's PrintShop Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/admin.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    body { font-family: 'Inter', sans-serif; }

    .stat-card { background:#fff; border-radius:14px; padding:1.5rem; border:1px solid #f0f0f0; box-shadow:0 1px 4px rgba(0,0,0,0.05); }
    .stat-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; }
    .stat-value { font-size:1.75rem; font-weight:800; color:#111; line-height:1; }
    .stat-label { font-size:0.78rem; color:#6b7280; font-weight:500; margin-top:4px; }
    .stat-growth { font-size:0.75rem; font-weight:600; margin-top:8px; }
    .growth-pos { color:#22c55e; }
    .growth-neg { color:#ef4444; }

    
    .chart-wrap { display:flex; align-items:flex-end; gap:6px; height:160px; padding-bottom:24px; position:relative; overflow-x:auto; }
    .bar-col { display:flex; flex-direction:column; align-items:center; flex-shrink:0; min-width:36px; }
    .bar { border-radius:6px 6px 0 0; width:28px; background:linear-gradient(180deg, #ff6b35, #e55a2b); transition:all 0.4s; cursor:default; position:relative; }
    .bar:hover::after { content:attr(data-tip); position:absolute; top:-32px; left:50%; transform:translateX(-50%); background:#111; color:#fff; padding:4px 8px; border-radius:6px; font-size:0.65rem; white-space:nowrap; z-index:10; }
    .bar-label { font-size:0.55rem; color:#9ca3af; margin-top:5px; text-align:center; white-space:nowrap; }
    .chart-grid { position:absolute; left:0; right:0; height:100%; display:flex; flex-direction:column; justify-content:space-between; pointer-events:none; }
    .chart-grid-line { border-top:1px dashed #f0f0f0; width:100%; }

    
    .product-bar-row { display:flex; align-items:center; gap:0.75rem; margin-bottom:0.75rem; }
    .product-bar-label { font-size:0.78rem; color:#374151; font-weight:500; min-width:130px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .product-bar-track { flex:1; height:10px; background:#f3f4f6; border-radius:50px; overflow:hidden; }
    .product-bar-fill { height:100%; border-radius:50px; background:linear-gradient(90deg, #ff6b35, #e55a2b); }
    .product-bar-val { font-size:0.73rem; font-weight:700; color:#111; min-width:70px; text-align:right; }

    
    .pipe-row { display:flex; align-items:center; justify-content:space-between; padding:0.65rem 0; border-bottom:1px solid #f3f4f6; }
    .pipe-row:last-child { border-bottom:none; }

    
    .sales-table { width:100%; border-collapse:collapse; }
    .sales-table th { background:#f9fafb; color:#6b7280; font-size:0.72rem; font-weight:600; padding:10px 14px; text-align:left; }
    .sales-table td { padding:10px 14px; font-size:0.82rem; border-bottom:1px solid #f3f4f6; color:#374151; }
    .sales-table tr:last-child td { border-bottom:none; }
    .sales-table tr:hover td { background:#fafafa; }

    .section-card { background:#fff; border-radius:14px; border:1px solid #f0f0f0; box-shadow:0 1px 4px rgba(0,0,0,0.05); overflow:hidden; }
    .section-header { padding:1.25rem 1.5rem; border-bottom:1px solid #f3f4f6; display:flex; align-items:center; justify-content:space-between; }
    .section-title { font-size:0.95rem; font-weight:700; color:#111; }
    .section-sub { font-size:0.75rem; color:#9ca3af; }
    </style>
</head>
<body class="bg-gray-50 admin-page">

<?php include 'sidebar.php'; ?>

<main class="ml-64 p-8 min-h-screen">

    
    <div class="flex items-center justify-between mb-8 flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Sales Report</h1>
            <p class="text-gray-500 text-sm mt-1">Revenue &amp; order analytics from your print shop</p>
        </div>
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <select id="exportType" style="padding:7px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:0.8rem; font-family:inherit; color:#374151; background:#fff; cursor:pointer; outline:none;" onchange="updateExportInput()">
                <option value="all">All Sales</option>
                <option value="day">By Day</option>
                <option value="month">By Month</option>
                <option value="year">By Year</option>
            </select>
            <input type="date" id="exportDay" value="<?= date('Y-m-d') ?>" style="padding:7px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:0.8rem; font-family:inherit; color:#374151; outline:none; display:none;">
            <input type="month" id="exportMonth" value="<?= date('Y-m') ?>" style="padding:7px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:0.8rem; font-family:inherit; color:#374151; outline:none; display:none;">
            <input type="number" id="exportYear" value="<?= date('Y') ?>" min="2020" max="2099" style="padding:7px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:0.8rem; font-family:inherit; color:#374151; outline:none; width:90px; display:none;">
            <button onclick="exportExcel()" style="padding:7px 16px; background:linear-gradient(135deg,#22c55e,#16a34a); color:#fff; border:none; border-radius:8px; font-size:0.8rem; font-weight:700; cursor:pointer; font-family:inherit; display:flex; align-items:center; gap:6px; transition:opacity 0.2s;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14,2 14,8 20,8"/><polyline points="8,13 12,17 16,13"/><line x1="12" y1="17" x2="12" y2="10"/></svg>
                Export Excel
            </button>
        </div>
    </div>

    <script>
    function updateExportInput() {
        const type = document.getElementById('exportType').value;
        document.getElementById('exportDay').style.display = type === 'day' ? '' : 'none';
        document.getElementById('exportMonth').style.display = type === 'month' ? '' : 'none';
        document.getElementById('exportYear').style.display = type === 'year' ? '' : 'none';
    }
    function exportExcel() {
        const type = document.getElementById('exportType').value;
        let dateVal = '';
        if (type === 'all') dateVal = 'all';
        else if (type === 'day') dateVal = document.getElementById('exportDay').value;
        else if (type === 'month') dateVal = document.getElementById('exportMonth').value;
        else dateVal = document.getElementById('exportYear').value;
        if (!dateVal) { alert('Please select a date.'); return; }
        window.location.href = 'sales.php?export=excel&type=' + type + '&date=' + encodeURIComponent(dateVal);
    }
    </script>

    
    <div class="grid gap-5 mb-8" style="grid-template-columns:repeat(5,1fr);">
        
        <div class="stat-card">
            <div class="flex items-center justify-between mb-3">
                <div class="stat-icon" style="background:rgba(255,107,53,0.1);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ff6b35" stroke-width="2.2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </div>
                <span class="text-xs text-gray-400 font-medium">All time</span>
            </div>
            <div class="stat-value">&#8369;<?= number_format($totalRev, 2) ?></div>
            <div class="stat-label">Order Revenue</div>
        </div>

        
        <div class="stat-card" style="border-left:3px solid #007DFE;">
            <div class="flex items-center justify-between mb-3">
                <div class="stat-icon" style="background:rgba(0,125,254,0.1);">
                    <img src="../download.jpg" style="height:22px;width:auto;">
                </div>
                <span class="text-xs text-gray-400 font-medium">GCash</span>
            </div>
            <div class="stat-value" style="color:#007DFE;">&#8369;<?= number_format($gcashOrderTotal, 2) ?></div>
            <div class="stat-label">GCash Down Payments, incl. pending (<?= $gcashOrderCount ?> recorded)</div>
        </div>

        
        <div class="stat-card">
            <div class="flex items-center justify-between mb-3">
                <div class="stat-icon" style="background:rgba(34,197,94,0.1);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.2"><polyline points="23,6 13.5,15.5 8.5,10.5 1,18"/><polyline points="17,6 23,6 23,12"/></svg>
                </div>
                <?php if ($growth !== null): ?>
                <span class="text-xs font-semibold <?= $growth >= 0 ? 'growth-pos' : 'growth-neg' ?>">
                    <?= $growth >= 0 ? '▲' : '▼' ?> <?= abs($growth) ?>% vs last mo.
                </span>
                <?php endif; ?>
            </div>
            <div class="stat-value">&#8369;<?= number_format($monthRev, 2) ?></div>
            <div class="stat-label">This Month (<?= date('F') ?>)</div>
        </div>

        
        <div class="stat-card">
            <div class="flex items-center justify-between mb-3">
                <div class="stat-icon" style="background:rgba(59,130,246,0.1);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2.2"><polyline points="20,6 9,17 4,12"/></svg>
                </div>
                <span class="text-xs text-gray-400 font-medium">Fulfilled</span>
            </div>
            <div class="stat-value"><?= number_format($totalOrders) ?></div>
            <div class="stat-label">Completed Orders</div>
        </div>

        
        <div class="stat-card">
            <div class="flex items-center justify-between mb-3">
                <div class="stat-icon" style="background:rgba(251,191,36,0.1);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fbbf24" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
                </div>
                <span class="text-xs text-gray-400 font-medium">In pipeline</span>
            </div>
            <div class="stat-value"><?= number_format($pendingOrders) ?></div>
            <div class="stat-label">Pending Orders</div>
        </div>
    </div>

    
    <div class="grid gap-6 mb-6" style="grid-template-columns:2fr 1fr;">

        
        <div class="section-card">
            <div class="section-header">
                <span class="section-title">Monthly Revenue</span>
                <span class="section-sub">Last 12 months &bull; Completed orders</span>
            </div>
            <div style="padding:1.5rem;">
                <?php if (empty($monthlyData)): ?>
                    <div style="text-align:center; color:#9ca3af; padding:2.5rem 0; font-size:0.85rem;">No completed sales yet.</div>
                <?php else: ?>
                <div class="chart-wrap" style="height:180px;">
                    <div class="chart-grid">
                        <div class="chart-grid-line"></div>
                        <div class="chart-grid-line"></div>
                        <div class="chart-grid-line"></div>
                        <div class="chart-grid-line"></div>
                    </div>
                    <?php foreach ($monthlyData as $m):
                        $barH = max(4, round(($m['rev'] / $maxMonthly) * 140));
                        $tip = $m['label'] . ': ₱' . number_format($m['rev'], 2) . ' (' . $m['orders'] . ' orders)';
                    ?>
                    <div class="bar-col">
                        <div class="bar" style="height:<?= $barH ?>px;" data-tip="<?= htmlspecialchars($tip) ?>"></div>
                        <div class="bar-label"><?= htmlspecialchars($m['label']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        
        <div class="section-card">
            <div class="section-header">
                <span class="section-title">Order Pipeline</span>
                <span class="section-sub">All statuses</span>
            </div>
            <div style="padding:1.25rem 1.5rem;">
                <?php
                $pipeColors = [
                    'In Cart'          => '#06b6d4',
                    'Processing'       => '#3b82f6',
                    'Production'       => '#a855f7',
                    'Ready for Pickup' => '#fbbf24',
                    'Completed'        => '#22c55e',
                    'Cancelled'        => '#ef4444',
                ];
                foreach ($pipeline as $p):
                    $col = $pipeColors[$p['status']] ?? '#9ca3af';
                ?>
                <div class="pipe-row">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="width:9px; height:9px; border-radius:50%; background:<?= $col ?>; flex-shrink:0; display:inline-block;"></span>
                        <span style="font-size:0.82rem; color:#374151; font-weight:500;"><?= htmlspecialchars($p['status']) ?></span>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:0.85rem; font-weight:700; color:#111;"><?= $p['cnt'] ?> orders</div>
                        <?php if ($p['rev'] > 0): ?>
                        <div style="font-size:0.7rem; color:#9ca3af;">&#8369;<?= number_format($p['rev'], 2) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    
    <div class="grid gap-6" style="grid-template-columns:1fr 1.6fr;">

        
        <div class="section-card">
            <div class="section-header">
                <span class="section-title">Top Products</span>
                <span class="section-sub">By revenue (completed)</span>
            </div>
            <div style="padding:1.25rem 1.5rem;">
                <?php if (empty($topProducts)): ?>
                    <div style="text-align:center; color:#9ca3af; padding:1.5rem 0; font-size:0.85rem;">No data yet.</div>
                <?php else: ?>
                    <?php foreach ($topProducts as $i => $tp):
                        $fillPct = $maxProduct > 0 ? round(($tp['total_rev'] / $maxProduct) * 100) : 0;
                    ?>
                    <div class="product-bar-row">
                        <span style="font-size:0.7rem; font-weight:700; color:#d1d5db; min-width:18px;"><?= $i+1 ?></span>
                        <span class="product-bar-label" title="<?= htmlspecialchars($tp['product_name']) ?>"><?= htmlspecialchars($tp['product_name']) ?></span>
                        <div class="product-bar-track">
                            <div class="product-bar-fill" style="width:<?= $fillPct ?>%;"></div>
                        </div>
                        <span class="product-bar-val">&#8369;<?= number_format($tp['total_rev'], 0) ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        
        <div class="section-card">
            <div class="section-header">
                <span class="section-title">Recent Sales</span>
                <span class="section-sub">Latest completed orders</span>
            </div>
            <?php if (empty($recentSales)): ?>
                <div style="text-align:center; color:#9ca3af; padding:2rem; font-size:0.85rem;">No completed sales yet.</div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="sales-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Revenue</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentSales as $s): ?>
                        <tr>
                            <td style="font-weight:600; color:#ff6b35;">ORD-<?= str_pad($s['id'], 3, '0', STR_PAD_LEFT) ?></td>
                            <td><?= htmlspecialchars($s['customer_name'] ?? 'Unknown') ?></td>
                            <td>
                                <div style="font-weight:500;"><?= htmlspecialchars($s['product_name']) ?></div>
                                <?php if (!empty($s['variant_name'])): ?>
                                    <div style="font-size:0.7rem; color:#9ca3af;"><?= htmlspecialchars($s['variant_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $s['quantity'] ?></td>
                            <td style="font-weight:700; color:#22c55e;">
                                &#8369;<?= number_format($s['price'] * $s['quantity'], 2) ?>
                            </td>
                            <td style="color:#9ca3af;"><?= date('M d, Y', strtotime($s['date_created'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    
    <div class="section-card mt-6">
        <div class="section-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <img src="../download.jpg" style="height:20px;width:auto;">
                <span class="section-title">GCash Chat Payments</span>
            </div>
            <span class="section-sub">Payments received via message requests &bull; This month: &#8369;<?= number_format($gcashMonthRev, 2) ?></span>
        </div>
        <?php if (empty($recentGcash)): ?>
            <div style="text-align:center; color:#9ca3af; padding:2rem; font-size:0.85rem;">No chat payment records yet. Order payment amounts are summarized above.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="sales-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th>Amount Paid</th>
                        <th>Full Price</th>
                        <th>Paid At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentGcash as $g):
                        $amtPaid = $g['payment_type'] === 'down' ? (float)$g['down_payment'] : (float)$g['full_price'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($g['customer_name'] ?? 'Unknown') ?></td>
                        <td>
                            <div style="font-weight:500;"><?= htmlspecialchars($g['product_name']) ?></div>
                            <?php if (!empty($g['description'])): ?>
                                <div style="font-size:0.7rem; color:#9ca3af;"><?= htmlspecialchars($g['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($g['payment_type'] === 'down'): ?>
                                <span style="background:#dbeafe;color:#1d4ed8;font-size:0.7rem;font-weight:700;padding:3px 8px;border-radius:20px;">Down Payment</span>
                            <?php else: ?>
                                <span style="background:#ffedd5;color:#c2410c;font-size:0.7rem;font-weight:700;padding:3px 8px;border-radius:20px;">Full Price</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:700; color:#22c55e;">&#8369;<?= number_format($amtPaid, 2) ?></td>
                        <td style="color:#6b7280;">&#8369;<?= number_format((float)$g['full_price'], 2) ?></td>
                        <td style="color:#9ca3af;"><?= date('M d, Y h:i A', strtotime($g['paid_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</main>
</body>
</html>
