<?php
session_start();

// 1. DATABASE CONNECTION
$servername = "localhost";
$username = "root";       
$password = "";           
$dbname = "rasacafe"; 

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Handle Staff Login
if (isset($_POST['login_staff'])) {
    $login_id = mysqli_real_escape_string($conn, $_POST['login_id']);
    $check_staff = mysqli_query($conn, "SELECT * FROM staff WHERE StaffID = '$login_id'");
    if (mysqli_num_rows($check_staff) > 0) {
        $_SESSION['staff_logged_in'] = true;
        $_SESSION['staff_id'] = $login_id;
        header("Location: index.php" . (isset($_GET['table']) ? "?table=" . $_GET['table'] : ""));
        exit();
    } else {
        echo "<script>alert('Invalid Staff ID! Access Denied.');</script>";
    }
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

$is_staff = isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true;

// Fetch all tables
$tables_query = mysqli_query($conn, "SHOW TABLES");
$tables = [];
while ($row = mysqli_fetch_row($tables_query)) {
    $tables[] = $row[0];
}

$current_table = isset($_GET['table']) ? $_GET['table'] : '';
$columns = [];
$primary_key = '';
$update_mode = false;
$edit_data = [];

// Determine Next and Previous tables dynamically
$prev_table = '';
$next_table = '';
if (!empty($current_table)) {
    $currentIndex = array_search($current_table, $tables);
    if ($currentIndex !== false) {
        if ($currentIndex > 0) {
            $prev_table = $tables[$currentIndex - 1];
        }
        if ($currentIndex < count($tables) - 1) {
            $next_table = $tables[$currentIndex + 1];
        }
    }

    $columns_query = mysqli_query($conn, "SHOW COLUMNS FROM `$current_table`");
    while ($col = mysqli_fetch_assoc($columns_query)) {
        $columns[] = $col['Field'];
        if ($col['Key'] == 'PRI') {
            $primary_key = $col['Field'];
        }
    }
    if (empty($primary_key) && !empty($columns)) { $primary_key = $columns[0]; }

    if ($is_staff) {
        // PROCESS MULTIPLE DELETE
        if (isset($_POST['bulk_delete']) && isset($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
            $ids_to_delete = [];
            foreach ($_POST['selected_ids'] as $id) {
                $ids_to_delete[] = "'" . mysqli_real_escape_string($conn, $id) . "'";
            }
            if (!empty($ids_to_delete)) {
                $query = "DELETE FROM `$current_table` WHERE `$primary_key` IN (" . implode(',', $ids_to_delete) . ")";
                if (!mysqli_query($conn, $query)) {
                    $error_msg = mysqli_real_escape_string($conn, mysqli_error($conn));
                    echo "<script>alert('Database Error during bulk delete:\\n" . $error_msg . "'); window.history.back();</script>";
                    exit();
                }
            }
            header("Location: index.php?table=$current_table"); exit();
        }

        // Process Insert / Update
        if (isset($_POST['save_data'])) {
            if (isset($_POST['mode']) && $_POST['mode'] == 'update') {
                $update_pairs = [];
                foreach ($columns as $col) {
                    $val = mysqli_real_escape_string($conn, $_POST['rows'][0][$col]);
                    $update_pairs[] = "`$col`='$val'";
                }
                $pk_val = mysqli_real_escape_string($conn, $_POST['pk_value']);
                $query = "UPDATE `$current_table` SET " . implode(', ', $update_pairs) . " WHERE `$primary_key`='$pk_val'";
                
                if (!mysqli_query($conn, $query)) {
                    $error_msg = mysqli_real_escape_string($conn, mysqli_error($conn));
                    echo "<script>alert('Database Error:\\n" . $error_msg . "'); window.history.back();</script>";
                    exit();
                }
            } else {
                if (isset($_POST['rows']) && is_array($_POST['rows'])) {
                    foreach ($_POST['rows'] as $row_data) {
                        if (empty(trim($row_data[$primary_key]))) continue;
                        
                        $fields = []; $values = [];
                        foreach ($columns as $col) {
                            $val = mysqli_real_escape_string($conn, $row_data[$col]);
                            $fields[] = "`$col`";
                            $values[] = "'$val'";
                        }
                        $query = "INSERT INTO `$current_table` (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";
                        
                        if (!mysqli_query($conn, $query)) {
                            $error_msg = mysqli_real_escape_string($conn, mysqli_error($conn));
                            echo "<script>alert('Database Error:\\n" . $error_msg . "'); window.history.back();</script>";
                            exit();
                        }
                    }
                }
            }
            header("Location: index.php?table=$current_table"); exit();
        }

        if (isset($_GET['edit'])) {
            $update_mode = true;
            $edit_id = mysqli_real_escape_string($conn, $_GET['edit']);
            $edit_res = mysqli_query($conn, "SELECT * FROM `$current_table` WHERE `$primary_key`='$edit_id'");
            if ($edit_res) { $edit_data = mysqli_fetch_assoc($edit_res); }
        }

        if (isset($_GET['delete'])) {
            $delete_id = mysqli_real_escape_string($conn, $_GET['delete']);
            $query = "DELETE FROM `$current_table` WHERE `$primary_key`='$delete_id'";
            mysqli_query($conn, $query);
            header("Location: index.php?table=$current_table"); exit();
        }
    }
}

// Function to enforce FirstName before LastName (Case-insensitive)
function reorderColumns($cols) {
    $fn_original_name = '';
    $ln_original_name = '';
    foreach ($cols as $c) {
        if (strtoupper($c) == 'FIRSTNAME') { $fn_original_name = $c; }
        if (strtoupper($c) == 'LASTNAME') { $ln_original_name = $c; }
    }
    if ($fn_original_name != '' && $ln_original_name != '') {
        $new_order = [];
        foreach ($cols as $c) {
            if ($c == $ln_original_name) continue;
            $new_order[] = $c;
            if ($c == $fn_original_name) {
                $new_order[] = $ln_original_name;
            }
        }
        return $new_order;
    }
    return $cols;
}
$display_columns = reorderColumns($columns);

// Match emojis directly to standard café tables
function getTableEmoji($tableName) {
    switch (strtoupper($tableName)) {
        case 'CUSTOMER': return '👥';
        case 'MENU': return '📜';
        case 'ORDERS': return '☕';
        case 'ORDERDETAIL': return '📝';
        case 'PAYMENT': return '💳';
        case 'STAFF': return '👔';
        case 'SHIFT': return '⏰';
        case 'SERVICE': return '🛎️';
        default: return '📊';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>RasaCafe - Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; color: #333; }
        header { background: #5c3d2e; color: white; padding: 25px 20px; display: flex; justify-content: space-between; align-items: center; }
        header h1 { margin: 0; font-size: 24px; }
        .btn-login { background-color: #b85c38; color: white; border: none; padding: 6px 16px; border-radius: 4px; }
        .btn-logout { background-color: #dc3545; color: white; padding: 6px 12px; border-radius: 4px; text-decoration: none; }
        .box { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .multi-row-item { background: #fdfaf6; padding: 15px; border: 1px dashed #b85c38; border-radius: 6px; margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; font-size: 11px; color: #5c3d2e; text-transform: uppercase; margin-bottom: 3px; }
        .form-group input { width: 100%; padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 8px; font-size: 13px; }
        table { width: 100%; text-align: left; }
        th { background-color: #5c3d2e; color: white; padding: 12px 10px; font-size: 12px; text-transform: uppercase; }
        td { padding: 12px 10px; border-bottom: 1px solid #eee; font-size: 13.5px; }
        .btn-primary { background-color: #b85c38; border: none; padding: 10px 20px; font-weight: 600; }
        .btn-remove-row { background: #dc3545; color: white; border: none; font-size: 11px; padding: 2px 8px; border-radius: 3px; float: right; }
        
        /* Clean Emoji Card Style */
        .dashboard-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            text-decoration: none;
            color: #333;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.04);
            border: 1px solid #eef0f2;
            transition: all 0.2s ease-in-out;
        }
        .dashboard-card .emoji-icon { font-size: 28px; }
        .dashboard-card h4 { 
            margin: 0; font-size: 16px; font-weight: 600; color: #5c3d2e;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .dashboard-card:hover {
            transform: translateY(-3px);
            border-color: #b85c38;
            box-shadow: 0 6px 15px rgba(184,92,56,0.12);
        }

        /* Form Hidden Transition */
        #add-record-box {
            display: none; /* Disembunyikan secara lalai */
            transition: all 0.3s ease-in-out;
        }
    </style>
</head>
<body>

<header>
    <div>
        <h1>☕ RASA CAFE MANAGEMENT SYSTEM</h1>
        <?php if ($is_staff): ?><span class="badge bg-success">Staff Mode</span><?php else: ?><span class="badge bg-secondary">View Only</span><?php endif; ?>
    </div>
    <div>
        <?php if ($is_staff): ?>
            <a href="index.php?logout=true" class="btn-logout">Logout</a>
        <?php else: ?>
            <form action="" method="POST" style="display:flex; gap:8px;">
                <input type="text" name="login_id" placeholder="Staff ID..." required style="padding:6px; border-radius:4px; border:1px solid #ccc;">
                <button type="submit" name="login_staff" class="btn-login">Login</button>
            </form>
        <?php endif; ?>
    </div>
</header>

<div class="container py-5">
    <?php if (empty($current_table)): ?>
        <div class="row mb-4">
            <div class="col">
                <h3 class="fw-bold" style="color: #5c3d2e;">Database Dashboard</h3>
                <p class="text-muted text-sm">Select a table below to start working:</p>
            </div>
        </div>

        <div class="row g-3">
            <?php foreach ($tables as $t): ?>
                <div class="col-xl-3 col-md-4 col-sm-6">
                    <a href="index.php?table=<?php echo $t; ?>" class="dashboard-card">
                        <span class="emoji-icon"><?php echo getTableEmoji($t); ?></span>
                        <h4><?php echo $t; ?></h4>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <a href="index.php" class="btn btn-secondary btn-sm">⬅ Back to Dashboard</a>
                <span class="ms-2 fw-bold text-uppercase" style="color: #5c3d2e;">📍 Current: <?php echo $current_table; ?></span>
            </div>
            
            <div class="d-flex gap-2">
                <?php if ($is_staff): ?>
                    <button type="button" class="btn btn-success btn-sm fw-bold" id="toggle-form-btn" onclick="toggleRecordForm()">
                        <?php echo $update_mode ? "✏️ Edit Record Mode" : "➕ Add New Records"; ?>
                    </button>
                <?php endif; ?>

                <div class="btn-group">
                    <?php if (!empty($prev_table)): ?>
                        <a href="index.php?table=<?php echo $prev_table; ?>" class="btn btn-outline-dark btn-sm">⏮ Prev</a>
                    <?php endif; ?>
                    
                    <?php if (!empty($next_table)): ?>
                        <a href="index.php?table=<?php echo $next_table; ?>" class="btn btn-dark btn-sm">Next ⏭</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($is_staff): ?>
            <div id="add-record-box" class="box" style="<?php echo $update_mode ? 'display: block;' : 'display: none;'; ?>">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-uppercase" style="color:#5c3d2e;">
                        <?php echo $update_mode ? "✏️ Edit Record" : "📝 Add Records Form"; ?>
                    </h5>
                    <div>
                        <?php if (!$update_mode): ?>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="addNewRow()">➕ Add Row</button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="toggleRecordForm()">❌ Close Form</button>
                    </div>
                </div>
                <form action="index.php?table=<?php echo $current_table; ?>" method="POST">
                    <?php if ($update_mode): ?>
                        <input type="hidden" name="mode" value="update">
                        <input type="hidden" name="pk_value" value="<?php echo $edit_id; ?>">
                    <?php endif; ?>
                    
                    <div id="dynamic-rows-container">
                        <div class="multi-row-item" data-row-index="0">
                            <div class="row g-2">
                                <?php foreach ($display_columns as $col): ?>
                                    <div class="col-md-3 form-group">
                                        <label><?php echo $col; ?></label>
                                        <input type="text" 
                                               name="rows[0][<?php echo $col; ?>]" 
                                               value="<?php echo $update_mode ? htmlspecialchars($edit_data[$col] ?? '') : ''; ?>" 
                                               <?php echo ($update_mode && $col == $primary_key) ? 'readonly' : ''; ?> 
                                               required>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="save_data" class="btn btn-primary w-100 mt-2">Submit All Records</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <form action="index.php?table=<?php echo $current_table; ?>" method="POST" id="bulk-delete-form" onsubmit="return confirm('Adakah anda pasti mahu padam semua rekod yang dipilih?');">
                    
                    <?php if ($is_staff): ?>
                        <div class="mb-3 d-flex justify-content-start">
                            <button type="submit" name="bulk_delete" class="btn btn-danger btn-sm" id="btn-delete-selected" disabled>
                                🗑️ Delete Selected
                            </button>
                        </div>
                    <?php endif; ?>

                    <div class="box p-0" style="overflow-x:auto;">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <?php if ($is_staff): ?>
                                        <th style="width: 40px; text-align: center;">
                                            <input type="checkbox" id="select-all-checkbox" onclick="toggleSelectAll(this)">
                                        </th>
                                    <?php endif; ?>
                                    
                                    <?php foreach ($display_columns as $col): ?><th><?php echo $col; ?></th><?php endforeach; ?>
                                    <?php if ($is_staff): ?><th>Actions</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $data_query = mysqli_query($conn, "SELECT * FROM `$current_table`");
                                if ($data_query && mysqli_num_rows($data_query) > 0) {
                                    while ($row = mysqli_fetch_assoc($data_query)) {
                                        $pk_val = $row[$primary_key] ?? '';
                                        echo "<tr>";
                                        
                                        if ($is_staff) {
                                            echo "<td style='text-align: center;'>
                                                    <input type='checkbox' name='selected_ids[]' value='" . htmlspecialchars($pk_val) . "' class='record-checkbox' onclick='checkButtonState()'>
                                                  </td>";
                                        }

                                        foreach ($display_columns as $col) { 
                                            echo "<td>" . htmlspecialchars($row[$col] ?? '') . "</td>"; 
                                        }
                                        
                                        if ($is_staff) {
                                            echo "<td>
                                                    <a href='index.php?table=$current_table&edit=$pk_val' class='btn btn-warning btn-sm'>Edit</a>
                                                    <a href='index.php?table=$current_table&delete=$pk_val' class='btn btn-danger btn-sm' onclick='return confirm(\"Are you sure?\")'>Delete</a>
                                                  </td>";
                                        }
                                        echo "</tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='100' class='text-center p-4'>No records found.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Fungsi untuk Toggle Show/Hide Borang Add Records
function toggleRecordForm() {
    const formBox = document.getElementById('add-record-box');
    const toggleBtn = document.getElementById('toggle-form-btn');
    
    if (formBox.style.display === 'none' || formBox.style.display === '') {
        formBox.style.display = 'block';
        if (toggleBtn) toggleBtn.innerHTML = '➖ Close Form';
    } else {
        formBox.style.display = 'none';
        if (toggleBtn) toggleBtn.innerHTML = '➕ Add New Records';
    }
}

let rowIndex = 1;
function addNewRow() {
    const container = document.getElementById('dynamic-rows-container');
    const firstRow = container.querySelector('.multi-row-item');
    const newRow = firstRow.cloneNode(true);
    newRow.setAttribute('data-row-index', rowIndex);
    
    const oldRemoveBtn = newRow.querySelector('.btn-remove-row');
    if (oldRemoveBtn) oldRemoveBtn.remove();
    
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn-remove-row';
    removeBtn.innerHTML = '❌ Remove';
    removeBtn.onclick = function() { newRow.remove(); };
    newRow.insertBefore(removeBtn, newRow.firstChild);

    const inputs = newRow.querySelectorAll('input');
    inputs.forEach(input => {
        input.removeAttribute('readonly'); 
        input.value = '';
        
        const nameAttr = input.getAttribute('name');
        if(nameAttr) {
            input.setAttribute('name', nameAttr.replace(/rows\[\d+\]/, `rows[${rowIndex}]`));
        }
    });
    container.appendChild(newRow);
    rowIndex++;
}

// JAVASCRIPT UNTUK FUNGSI SELECT
function toggleSelectAll(source) {
    const checkboxes = document.querySelectorAll('.record-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = source.checked;
    });
    checkButtonState();
}

function checkButtonState() {
    const checkboxes = document.querySelectorAll('.record-checkbox');
    const deleteBtn = document.getElementById('btn-delete-selected');
    const selectAllCheckbox = document.getElementById('select-all-checkbox');
    
    let checkedCount = 0;
    checkboxes.forEach(checkbox => {
        if (checkbox.checked) checkedCount++;
    });

    if (deleteBtn) {
        deleteBtn.disabled = (checkedCount === 0);
    }

    if (selectAllCheckbox) {
        selectAllCheckbox.checked = (checkedCount === checkboxes.length && checkboxes.length > 0);
    }
}
</script>
</body>
</html>