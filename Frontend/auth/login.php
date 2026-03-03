<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// The $error variable is now handled client-side
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zuru Bank Login</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Maintain the sharp aesthetic */
        .sharp-edge {
            border-radius: 0 !important;
        }
        .btn-primary { 
            background-color: #374151; 
            color: white; 
            border: 1px solid #374151;
            transition: background-color 0.15s;
        }
        .btn-primary:hover {
            background-color: #1f2937;
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 font-['Inter'] min-h-screen flex items-center justify-center p-4">

    <!-- Header (Fixed top bar for Zuru Bank branding) -->
    <header class="fixed top-0 left-0 w-full bg-gray-900 text-white p-4 text-center text-xl font-bold sharp-edge shadow-lg z-50">
        ZURU BANK
    </header>

    <!-- Login Card (Sharp edges) -->
    <div class="bg-white p-8 max-w-sm w-full border border-gray-300 sharp-edge shadow-xl">
        <h2 class="text-2xl font-bold mb-6 text-center text-gray-900">Access Your Account</h2>
        
        <!-- Error message container (Initially hidden) -->
        <div id="errorMessage" class='hidden bg-red-50 border border-red-600 text-red-700 px-4 py-3 sharp-edge relative mb-4 text-sm font-medium' role='alert'>
            <!-- Error message inserted here by JavaScript -->
        </div>
        
        <form id="loginForm" class="space-y-4">
            <!-- Input fields with sharp edges -->
            <input type="email" id="email" name="email" placeholder="Email Address" required class="sharp-edge w-full px-4 py-2 border border-gray-300 focus:outline-none focus:ring-1 focus:ring-gray-900 focus:border-gray-900"/>
            <input type="password" id="password" name="password" placeholder="Password" required class="sharp-edge w-full px-4 py-2 border border-gray-300 focus:outline-none focus:ring-1 focus:ring-gray-900 focus:border-gray-900"/>
            
            <!-- Primary login button -->
            <button type="submit" id="loginBtn" class="w-full btn-primary px-6 py-3 font-bold sharp-edge cursor-pointer">
                Login Securely
            </button>
        </form>
        
        <p class="mt-6 text-center text-sm">
            Don't have an account? 
            <a href="register.php" class="text-gray-900 font-semibold hover:text-gray-700 transition duration-150">Open a New Account</a>
        </p>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            const errorDiv = document.getElementById('errorMessage');
            const loginBtn = document.getElementById('loginBtn');
            
            // Function to display errors
            const showError = (message) => {
                errorDiv.textContent = message;
                errorDiv.classList.remove('hidden');
            };

            // Function to hide errors
            const hideError = () => {
                errorDiv.classList.add('hidden');
            };

            hideError();
            loginBtn.textContent = 'Logging in...';
            loginBtn.disabled = true;

            try {
                // The URL to your backend API endpoint
                const loginUrl = '../../Backend/auth/login.php'; 
                
                const response = await fetch(loginUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    // Send data as JSON, which your backend API expects
                    body: JSON.stringify({ email: email, password: password })
                });

                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }

                const data = await response.json();

                if (data.status === 'success') {
                    // Successful login: Redirect to the dashboard
                    window.location.href = '../dashboard/user_dashboard.php';
                } else {
                    // Handle API-specific errors (e.g., "Invalid password")
                    showError(data.message || "Login failed. Please try again.");
                }

            } catch (error) {
                console.error('Login error:', error);
                showError("An unexpected error occurred. Please check your network.");
            } finally {
                loginBtn.textContent = 'Login Securely';
                loginBtn.disabled = false;
            }
        });
    </script>

</body>
</html>
