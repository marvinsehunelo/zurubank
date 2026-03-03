<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zuru Bank - Private Banking</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">

<!-- Top Nav -->
<header class="top-nav fixed top-0 left-0 w-full bg-white bg-opacity-90 backdrop-blur-md shadow-sm z-50">
    <div class="container mx-auto px-6 py-4 flex justify-between items-center">
        <div class="logo text-lg font-bold text-gray-900">ZURU BANK</div>
        <nav class="flex space-x-6">
            <a href="#" class="text-gray-600 hover:text-gray-900 transition-colors duration-200">Home</a>
            <a href="#" class="text-gray-600 hover:text-gray-900 transition-colors duration-200">About</a>
            <a href="#" class="text-gray-600 hover:text-gray-900 transition-colors duration-200">Products</a>
            <a href="#" class="text-gray-600 hover:text-gray-900 transition-colors duration-200">Contact</a>
            <a href="auth/login.php" class="text-gray-900 font-semibold px-4 py-2 border border-gray-900 hover:bg-gray-100 transition-colors duration-200">Login</a>
        </nav>
    </div>
</header>

<!-- Hero Section -->
<section class="hero min-h-screen flex items-center justify-center text-gray-900 pt-20">
    <div class="container mx-auto px-6 flex flex-col md:flex-row items-center justify-between">
        <div class="hero-text text-left max-w-2xl">
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold leading-tight mb-4">Open Your Zuru Account and Manage Money Effortlessly</h1>
            <p class="text-lg md:text-xl font-light mb-8">Enjoy seamless deposits, transfers, and withdrawals with a modern online banking experience.</p>
            <div class="flex space-x-4">
                <a href="auth/register.php" class="btn btn-primary bg-gray-900 text-white px-6 py-3 font-bold shadow-lg hover:bg-gray-800 transition-colors duration-200">Open Account</a>
                <a href="#" class="btn btn-secondary text-gray-900 border border-gray-900 px-6 py-3 font-bold hover:bg-gray-900 hover:text-white transition-colors duration-200">Learn More</a>
            </div>
        </div>
        <!-- Removed the hero image as requested -->
    </div>
</section>

<!-- Optional Sections -->
<section class="features py-20 bg-gray-50">
    <div class="container mx-auto px-6 text-center">
        <h2 class="text-3xl md:text-4xl font-bold mb-12 text-gray-800">Why Choose Zuru Bank?</h2>
        <div class="feature-cards flex flex-col md:flex-row justify-center space-y-8 md:space-y-0 md:space-x-8">
            <div class="card bg-white p-8 shadow-lg max-w-sm w-full">
                <h3 class="text-xl font-bold mb-4 text-gray-900">Secure</h3>
                <p class="text-gray-600">Your money is protected with enterprise-level security protocols.</p>
            </div>
            <div class="card bg-white p-8 shadow-lg max-w-sm w-full">
                <h3 class="text-xl font-bold mb-4 text-gray-900">Fast</h3>
                <p class="text-gray-600">Transactions are completed instantly with full transparency.</p>
            </div>
            <div class="card bg-white p-8 shadow-lg max-w-sm w-full">
                <h3 class="text-xl font-bold mb-4 text-gray-900">Easy</h3>
                <p class="text-gray-600">Manage all your accounts and payments in one centralized dashboard.</p>
            </div>
        </div>
    </div>
</section>

</body>
</html>
