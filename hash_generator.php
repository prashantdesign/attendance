<?php
// hash_generator.php
// This file is for one-time use to create a secure password hash.
// After you use it, you can delete it from your server.

// The password you want to hash.
$passwordToHash = 'Pr@mukh123';

// Generate the hash using PHP's recommended standard algorithm.
$hashedPassword = password_hash($passwordToHash, PASSWORD_DEFAULT);

// Display the hash in a user-friendly way.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Password Hash Generator</title>
    <style>
        body { font-family: sans-serif; padding: 2em; line-height: 1.6; }
        .hash-container { background-color: #f0f0f0; padding: 1em; border: 1px solid #ccc; border-radius: 5px; font-family: monospace; word-wrap: break-word; }
        .instructions { margin-bottom: 1em; }
    </style>
</head>
<body>
    <h1>Password Hash Generator</h1>
    <div class="instructions">
        <p>Your secure password hash for '<b><?php echo htmlspecialchars($passwordToHash); ?></b>' has been generated.</p>
        <p><b>Step 1:</b> Copy the entire hash string from the box below.</p>
        <p><b>Step 2:</b> Go to phpMyAdmin and run the SQL query provided in the guide, pasting this hash into the appropriate place.</p>
    </div>
    <div class="hash-container" id="hash-output">
        <?php echo htmlspecialchars($hashedPassword); ?>
    </div>
    <p><button onclick="copyHash()">Copy Hash</button></p>

    <script>
        function copyHash() {
            const hashText = document.getElementById('hash-output').innerText;
            navigator.clipboard.writeText(hashText).then(() => {
                alert('Hash copied to clipboard!');
            }, (err) => {
                alert('Failed to copy hash. Please copy it manually.');
                console.error('Copy error', err);
            });
        }
    </script>
</body>
</html>

