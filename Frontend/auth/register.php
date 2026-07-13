<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Zuru Bank Registration</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    /* Custom styles for better UX */
    .loading-spinner {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(255,255,255,.3);
        border-radius: 50%;
        border-top-color: #fff;
        animation: spin 1s ease-in-out infinite;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    .btn-loading {
        opacity: 0.7;
        cursor: not-allowed;
    }
    
    .password-strength {
        height: 4px;
        margin-top: 6px;
        border-radius: 2px;
        transition: all 0.3s ease;
        background: #e5e7eb;
    }
    
    .password-strength.weak { background: #ef4444; width: 33%; }
    .password-strength.medium { background: #f59e0b; width: 66%; }
    .password-strength.strong { background: #10b981; width: 100%; }
</style>
</head>
<body class="bg-gray-100 text-gray-800 font-inter min-h-screen flex items-center justify-center p-4">

<header class="fixed top-0 left-0 w-full bg-gray-900 text-white p-3 text-center text-lg font-bold shadow-sm z-50">
    ZURU BANK
</header>

<div class="bg-white p-8 max-w-sm w-full shadow-lg mt-16 rounded-lg">
    <h2 class="text-2xl font-bold mb-6 text-center">Create Account</h2>
    
    <div id="errorMsg" class="hidden bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 text-sm"></div>
    <div id="successMsg" class="hidden bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4 text-sm"></div>

    <form id="registerForm" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
            <input type="text" name="full_name" placeholder="John Doe" required 
                   class="w-full px-4 py-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-transparent transition"/>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
            <input type="email" name="email" placeholder="john@example.com" required 
                   class="w-full px-4 py-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-transparent transition"/>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
            <input type="tel" name="phone" placeholder="+267 71 234 567" required 
                   class="w-full px-4 py-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-transparent transition"/>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <div class="relative">
                <input type="password" id="password" name="password" placeholder="Minimum 8 characters" required 
                       class="w-full px-4 py-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-transparent transition"/>
                <button type="button" onclick="togglePassword()" 
                        class="absolute right-2 top-2 text-gray-600 text-sm hover:text-gray-900 font-medium">
                    Show
                </button>
            </div>
            <div id="passwordStrength" class="password-strength"></div>
            <p id="passwordHelp" class="text-xs text-gray-500 mt-1">Use 8+ characters with a mix of letters, numbers & symbols</p>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
            <input type="password" name="confirm_password" placeholder="Re-enter password" required 
                   class="w-full px-4 py-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-transparent transition"/>
        </div>
        
        <button type="submit" id="submitBtn" 
                class="w-full bg-gray-900 text-white border border-gray-900 px-6 py-2.5 font-bold rounded cursor-pointer hover:bg-gray-800 transition duration-200">
            Register
        </button>
    </form>
    
    <p class="mt-4 text-center text-sm">
        Already have an account? 
        <a href="login.php" class="text-gray-900 font-semibold hover:underline">Login</a>
    </p>
</div>

<script>
// Toggle password visibility
function togglePassword() {
    const pwd = document.getElementById('password');
    const toggleBtn = event.target;
    if (pwd.type === 'password') {
        pwd.type = 'text';
        toggleBtn.textContent = 'Hide';
    } else {
        pwd.type = 'password';
        toggleBtn.textContent = 'Show';
    }
}

// Password strength checker
document.getElementById('password').addEventListener('input', function() {
    const password = this.value;
    const strengthBar = document.getElementById('passwordStrength');
    let strength = 0;
    
    if (password.length >= 8) strength++;
    if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength++;
    if (password.match(/\d/)) strength++;
    if (password.match(/[^a-zA-Z\d]/)) strength++;
    
    strengthBar.className = 'password-strength';
    if (password.length === 0) {
        strengthBar.style.width = '0%';
        return;
    }
    
    if (strength <= 1) {
        strengthBar.classList.add('weak');
    } else if (strength === 2) {
        strengthBar.classList.add('medium');
    } else if (strength >= 3) {
        strengthBar.classList.add('strong');
    }
});

// Form submission
document.getElementById('registerForm').addEventListener('submit', async function(e){
    e.preventDefault();
    
    // Reset messages
    document.getElementById('errorMsg').classList.add('hidden');
    document.getElementById('successMsg').classList.add('hidden');
    
    // Get form values
    const full_name = this.full_name.value.trim();
    const email = this.email.value.trim();
    const phone = this.phone.value.trim();
    const password = this.password.value;
    const confirm_password = this.confirm_password.value;
    
    // Validation
    if (!full_name || !email || !phone || !password) {
        showError('Please fill in all fields');
        return;
    }
    
    if (password !== confirm_password) {
        showError('Passwords do not match');
        return;
    }
    
    if (password.length < 8) {
        showError('Password must be at least 8 characters long');
        return;
    }
    
    // Validate email format
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        showError('Please enter a valid email address');
        return;
    }
    
    // Validate phone (basic - at least 8 digits)
    const phoneRegex = /^[0-9+\-\s()]{8,}$/;
    if (!phoneRegex.test(phone)) {
        showError('Please enter a valid phone number');
        return;
    }
    
    // Disable button and show loading state
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="loading-spinner"></span> Processing...';
    submitBtn.disabled = true;
    submitBtn.classList.add('btn-loading');
    
    try {
        // Build JSON payload
        const payload = { full_name, email, phone, password };
        
        // Determine the correct API URL based on environment
        // This will work in both localhost and production
        let apiUrl;
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            // Local development
            apiUrl = 'http://localhost/zurubank/Backend/auth/register.php';
        } else {
            // Production - use relative path
            apiUrl = '/Backend/auth/register.php';
        }
        
        console.log('Sending request to:', apiUrl);
        
        const res = await fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        
        // Check if response is OK
        if (!res.ok) {
            throw new Error(`Server error: ${res.status} ${res.statusText}`);
        }
        
        const data = await res.json();
        console.log('Server response:', data);
        
        if (data.success) {
            // Show success message
            const successDiv = document.getElementById('successMsg');
            successDiv.textContent = data.message || 'Registration successful! Redirecting to login...';
            successDiv.classList.remove('hidden');
            
            // Redirect after 2 seconds
            setTimeout(() => {
                window.location.href = 'login.php';
            }, 2000);
        } else {
            // Show error message
            showError(data.message || 'Registration failed. Please try again.');
        }
        
    } catch(err) {
        console.error('Registration error:', err);
        
        // Show user-friendly error message
        let errorMsg = 'An error occurred during registration. Please try again.';
        if (err.message.includes('Failed to fetch') || err.message.includes('NetworkError')) {
            errorMsg = 'Cannot connect to the server. Please check your internet connection and try again.';
        } else if (err.message.includes('HTTP error')) {
            errorMsg = 'Server error. Please try again later.';
        }
        
        showError(errorMsg);
    } finally {
        // Reset button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        submitBtn.classList.remove('btn-loading');
    }
});

// Helper function to show errors
function showError(message) {
    const errorDiv = document.getElementById('errorMsg');
    errorDiv.textContent = message;
    errorDiv.classList.remove('hidden');
    document.getElementById('successMsg').classList.add('hidden');
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        errorDiv.classList.add('hidden');
    }, 5000);
}

// Add input event listeners to clear errors when user types
document.querySelectorAll('#registerForm input').forEach(input => {
    input.addEventListener('input', function() {
        document.getElementById('errorMsg').classList.add('hidden');
        document.getElementById('successMsg').classList.add('hidden');
    });
});
</script>

</body>
</html>
