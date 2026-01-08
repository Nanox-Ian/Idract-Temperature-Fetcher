<?php
// Example data rows (you can replace this with data from a database or file)
$dataRows = [
    [
        'fa_code' => 'FA001',
        'mat_type' => 'Steel',
        'prmode' => 'Auto',
        'description' => 'Steel Rod',
        'price' => 120.50,
        'beg_stock' => 100,
        'received' => 50,
        'other_in' => 10,
        'wip' => 5,
        'return' => 2,
        'issued' => 40,
        'other_out' => 3,
        'wip_issued' => 4,
        'end_stock' => 120,
        'end_cost' => 14460.00
    ],
    // Add more rows as needed
];

// Organize data by fa_code
$organizedData = [];

foreach ($dataRows as $row) {
    $faCode = $row['fa_code'];
    $organizedData[$faCode] = $row;
}

// Example: Access data by fa_code
$targetCode = 'FA001';
if (isset($organizedData[$targetCode])) {
    echo "Description for $targetCode: " . $organizedData[$targetCode]['description'];
} else {
    echo "No data found for $targetCode.";
}
?>
