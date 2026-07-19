<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// CHECK FOR OAUTH RETURN URL
// ============================================================
if (isset($_GET['return_url']) && !empty($_GET['return_url'])) {
    $_SESSION['oauth_return_url'] = $_GET['return_url'];
}

// If already logged in and there's an OAuth return URL, redirect immediately
if (isset($_SESSION['user']['id']) && isset($_SESSION['oauth_return_url'])) {
    $return_url = $_SESSION['oauth_return_url'];
    unset($_SESSION['oauth_return_url']);
    header("Location: " . $return_url);
    exit;
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
        
        <!-- Success message for OAuth redirect -->
        <div id="successMessage" class='hidden bg-green-50 border border-green-600 text-green-700 px-4 py-3 sharp-edge relative mb-4 text-sm font-medium' role='alert'>
            <!-- Success message inserted here by JavaScript -->
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
        
        <!-- Hidden field to pass return_url to JS -->
        <input type="hidden" id="returnUrl" value="<?php echo htmlspecialchars($_GET['return_url'] ?? $_SESSION['oauth_return_url'] ?? ''); ?>">
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            const errorDiv = document.getElementById('errorMessage');
            const successDiv = document.getElementById('successMessage');
            const loginBtn = document.getElementById('loginBtn');
            const returnUrl = document.getElementById('returnUrl').value;
            
            // Function to display errors
            const showError = (message) => {
                errorDiv.textContent = message;
                errorDiv.classList.remove('hidden');
                successDiv.classList.add('hidden');
            };

            // Function to show success
            const showSuccess = (message) => {
                successDiv.textContent = message;
                successDiv.classList.remove('hidden');
                errorDiv.classList.add('hidden');
            };

            // Function to hide all messages
            const hideMessages = () => {
                errorDiv.classList.add('hidden');
                successDiv.classList.add('hidden');
            };

            hideMessages();
            loginBtn.textContent = 'Logging in...';
            loginBtn.disabled = true;

            try {
                // The URL to your backend API endpoint
                const loginUrl = '../../Backend/auth/login.php' + (returnUrl ? '?return_url=' + encodeURIComponent(returnUrl) : '');
                
                const response = await fetch(loginUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ email: email, password: password })
                });

                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }

                const data = await response.json();

                if (data.status === 'success') {
                    // Check if this is an OAuth redirect
                    if (data.oauth_redirect && data.redirect_url) {
                        showSuccess('Login successful! Redirecting to authorization...');
                        // Redirect to OAuth consent screen
                        setTimeout(() => {
                            window.location.href = data.redirect_url;
                        }, 500);
                    } else {
                        // Normal login - go to dashboard
                        window.location.href = '../dashboard/user_dashboard.php';
                    }
                } else {
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

        // Check for OAuth return URL on page load
        document.addEventListener('DOMContentLoaded', function() {
            const returnUrl = document.getElementById('returnUrl').value;
            if (returnUrl) {
                console.log('OAuth flow detected, will redirect after login');
            }
        });
    </script>

</body>
</html>
