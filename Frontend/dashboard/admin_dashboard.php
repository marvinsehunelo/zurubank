<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zurubank Admin Console</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@100..900&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .bg-zurubank { background-color: #0d2847; } /* Deep Blue */
        .text-accent { color: #facc15; } /* Yellow Accent */
        .btn-primary { background-color: #facc15; color: #0d2847; transition: all 0.2s; }
        .btn-primary:hover { background-color: #eab308; }
        .card { backdrop-filter: blur(10px); background-color: rgba(255, 255, 255, 0.9); border: 1px solid rgba(255, 255, 255, 0.2); }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">

    <div id="app" class="w-full max-w-4xl p-4 sm:p-6 lg:p-8">
        <!-- Message/Notification Box -->
        <div id="message-box" class="hidden fixed top-0 left-0 right-0 p-4 text-center text-sm font-medium z-50"></div>
        
        <div id="login-view" class="flex flex-col items-center justify-center min-h-screen transition-opacity duration-300">
            <div class="card w-full max-w-md p-8 rounded-xl shadow-2xl">
                <h1 class="text-4xl font-extrabold text-center text-zurubank mb-2">Zurubank Admin</h1>
                <p class="text-center text-gray-600 mb-8">Secure Access Required</p>

                <form id="login-form" class="space-y-6">
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                        <input type="text" id="username" name="username" required class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-accent focus:border-accent">
                    </div>
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                        <input type="password" id="password" name="password" required class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-accent focus:border-accent">
                    </div>
                    <button type="submit" id="login-btn" class="w-full py-2 px-4 border border-transparent rounded-lg shadow-sm text-lg font-semibold btn-primary hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-accent">
                        Sign In
                    </button>
                    <p class="text-xs text-gray-500 mt-4 text-center">Default: admin_user / supersecurepassword</p>
                </form>
            </div>
        </div>

        <div id="dashboard-view" class="hidden transition-opacity duration-300">
            <header class="bg-zurubank p-4 sm:p-6 rounded-lg shadow-lg flex justify-between items-center mb-8">
                <h2 class="text-3xl font-bold text-white">Admin Dashboard</h2>
                <button id="logout-btn" class="text-white hover:text-accent font-medium transition-colors">Logout</button>
            </header>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Main Action Card -->
                <div class="card p-6 rounded-xl shadow-lg lg:col-span-2">
                    <h3 class="text-2xl font-semibold text-zurubank mb-4">Cron & Maintenance Tasks</h3>
                    <div class="border-t border-gray-200 pt-4">
                        <p class="text-gray-700 mb-4">
                            This task processes all currently **active vouchers** where the designated **swap expiry time has passed**. It moves the locked-in `creation_fee` from the Escrow account and splits it 60/40 between the Partner Bank and the Middleman Revenue accounts, then marks the vouchers as `expired`.
                        </p>
                        
                        <button id="process-swaps-btn" class="w-full sm:w-auto py-3 px-6 rounded-lg font-semibold text-white bg-red-600 hover:bg-red-700 shadow-md transition-all flex items-center justify-center focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                            <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white hidden" id="spinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Process Expired Swaps Now
                        </button>
                        <p class="text-sm text-gray-500 mt-2">Only run this task when needed for daily/weekly cleanup.</p>
                    </div>
                </div>

                <!-- Status Card -->
                <div class="card p-6 rounded-xl shadow-lg bg-gray-50">
                    <h3 class="text-2xl font-semibold text-gray-800 mb-4">System Status</h3>
                    <ul class="space-y-3 text-gray-600 text-sm">
                        <li class="flex items-center"><span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span> Database Connection: OK</li>
                        <li class="flex items-center"><span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span> Ledger Accounts: OK</li>
                        <li class="flex items-center"><span class="w-3 h-3 bg-yellow-500 rounded-full mr-2"></span> Last Cron Run: Unknown</li>
                        <li class="flex items-center"><span class="w-3 h-3 bg-gray-400 rounded-full mr-2"></span> Active Admin: <span id="admin-username"></span></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        const AUTH_ENDPOINT = 'admin_login.php'; // Update path as needed
        const PROCESS_ENDPOINT = 'unused_swap_slips.php'; // Update path as needed

        const loginView = document.getElementById('login-view');
        const dashboardView = document.getElementById('dashboard-view');
        const loginForm = document.getElementById('login-form');
        const logoutBtn = document.getElementById('logout-btn');
        const processSwapsBtn = document.getElementById('process-swaps-btn');
        const messageBox = document.getElementById('message-box');
        const spinner = document.getElementById('spinner');

        let currentUsername = '';

        function showMessage(message, type = 'success') {
            messageBox.textContent = message;
            messageBox.className = 'fixed top-0 left-0 right-0 p-4 text-center text-sm font-medium z-50';
            
            if (type === 'success') {
                messageBox.classList.add('bg-green-500', 'text-white');
            } else if (type === 'error') {
                messageBox.classList.add('bg-red-500', 'text-white');
            } else {
                messageBox.classList.add('bg-blue-500', 'text-white');
            }

            messageBox.classList.remove('hidden');
            setTimeout(() => {
                messageBox.classList.add('hidden');
            }, 5000);
        }

        function setView(isAuthenticated) {
            if (isAuthenticated) {
                loginView.classList.add('hidden');
                dashboardView.classList.remove('hidden');
                document.getElementById('admin-username').textContent = currentUsername;
            } else {
                dashboardView.classList.add('hidden');
                loginView.classList.remove('hidden');
            }
        }

        async function checkAuth() {
            try {
                const response = await fetch(AUTH_ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=check'
                });
                const result = await response.json();
                
                if (result.success) {
                    currentUsername = result.user || 'Admin';
                    setView(true);
                } else {
                    setView(false);
                }
            } catch (error) {
                showMessage('Failed to connect to authentication server.', 'error');
                setView(false);
            }
        }

        // --- Event Listeners ---

        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const btn = document.getElementById('login-btn');
            btn.disabled = true;
            btn.textContent = 'Logging In...';

            const formData = new FormData(loginForm);
            formData.append('action', 'login');

            try {
                const response = await fetch(AUTH_ENDPOINT, {
                    method: 'POST',
                    body: new URLSearchParams(formData).toString(),
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });
                const result = await response.json();

                if (result.success) {
                    currentUsername = formData.get('username');
                    setView(true);
                    showMessage('Welcome, ' + currentUsername + '!', 'success');
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('Network error during login.', 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Sign In';
            }
        });

        logoutBtn.addEventListener('click', async () => {
            if (!confirm('Are you sure you want to log out?')) return;

            try {
                const response = await fetch(AUTH_ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=logout'
                });
                const result = await response.json();

                if (result.success) {
                    currentUsername = '';
                    setView(false);
                    showMessage('Logged out successfully.', 'info');
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('Network error during logout.', 'error');
            }
        });

        processSwapsBtn.addEventListener('click', async () => {
            const isConfirmed = confirm("WARNING: This will execute the CRON job to split fees from all expired vouchers. Continue?");
            if (!isConfirmed) return;

            processSwapsBtn.disabled = true;
            spinner.classList.remove('hidden');

            try {
                const response = await fetch(PROCESS_ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=process_expired_swaps'
                });
                const result = await response.json();

                if (result.success) {
                    showMessage(result.message, 'success');
                    console.log('Processing details:', result);
                } else {
                    showMessage('Processing failed: ' + result.message, 'error');
                }
            } catch (error) {
                showMessage('Network error during swap processing.', 'error');
            } finally {
                processSwapsBtn.disabled = false;
                spinner.classList.add('hidden');
            }
        });

        // Initial check on load
        checkAuth();
    </script>
</body>
</html>
