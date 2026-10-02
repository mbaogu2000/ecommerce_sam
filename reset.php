<?php
include __DIR__ . "/pages/server/conn.php";
$db = __DIR__ . "/pages/server/db.sql";

try {
    // Read the actual SQL contents from the file
    $sql = file_get_contents($db);

    if ($sql !== false && $conn->multi_query($sql)) {
        // Clear all result sets from multi_query to avoid connection blocks
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());

        echo "Database import successful.";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}