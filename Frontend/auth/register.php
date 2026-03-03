<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Zuru Bank Registration</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-gray-100 text-gray-800 font-inter min-h-screen flex items-center justify-center p-4">

<header class="fixed top-0 left-0 w-full bg-gray-900 text-white p-3 text-center text-lg font-bold shadow-sm z-50">
    ZURU BANK
</header>

<div class="bg-white p-8 max-w-sm w-full shadow-lg mt-16">
    <h2 class="text-2xl font-bold mb-6 text-center">Create Account</h2>
    <div id="errorMsg" class="hidden bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 text-sm"></div>

    <form id="registerForm" class="space-y-4">
        <input type="text" name="full_name" placeholder="Full Name" required class="w-full px-4 py-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-gray-900"/>
        <input type="email" name="email" placeholder="Email" required class="w-full px-4 py-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-gray-900"/>
        <input type="text" name="phone" placeholder="Phone Number" required class="w-full px-4 py-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-gray-900"/>
        
        <div class="relative">
            <input type="password" id="password" name="password" placeholder="Password" required class="w-full px-4 py-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-gray-900"/>
            <button type="button" onclick="togglePassword()" class="absolute right-2 top-2 text-gray-600 text-sm">Show</button>
        </div>
        
        <input type="password" name="confirm_password" placeholder="Confirm Password" required class="w-full px-4 py-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-gray-900"/>
        
        <button type="submit" class="w-full bg-gray-900 text-white border border-gray-900 px-6 py-2 font-bold cursor-pointer hover:bg-gray-800">Register</button>
    </form>
    <p class="mt-4 text-center text-sm">Already have an account? <a href="login.php" class="text-gray-900 font-semibold hover:underline">Login</a></p>
</div>

<script>
function togglePassword() {
    const pwd = document.getElementById('password');
    pwd.type = pwd.type === 'password' ? 'text' : 'password';
}

document.getElementById('registerForm').addEventListener('submit', async function(e){
    e.preventDefault();

    const full_name = this.full_name.value.trim();
    const email = this.email.value.trim();
    const phone = this.phone.value.trim();
    const password = this.password.value;
    const confirm_password = this.confirm_password.value;

    if(password !== confirm_password){
        alert("Passwords do not match");
        return;
    }

    // Build JSON payload instead of FormData
    const payload = { full_name, email, phone, password };

    try {
        const res = await fetch('http://localhost/zurubank/Backend/auth/register.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        // Check if fetch succeeded
        if(!res.ok) throw new Error(`HTTP error! Status: ${res.status}`);

        const data = await res.json();

        const errorDiv = document.getElementById('errorMsg');
        if(data.success){
            alert(data.message);
            window.location.href = 'login.php';
        } else {
            errorDiv.textContent = data.message;
            errorDiv.classList.remove('hidden');
        }

    } catch(err){
        console.error(err);
        alert("An error occurred. Check console for details.");
    }
});
</script>


</body>
</html>
