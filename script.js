document.addEventListener('DOMContentLoaded', async () => { // Make listener async
    // --- Configuration ---
    const API_URL = 'api.php';
    // NOTE: If you get 404 errors on login/register/update, ensure your PHP server
    // is running in the project root directory (e:/Charged-Study/Audio_Overview - Copy/Study_Track_Pro_app)
    // and can correctly handle requests to 'api.php'. Example command: php -S localhost:8000
    // No longer need local storage keys for user, session handled by backend/cookies

    // Status Levels & Scores
    const statusLevels = {
        not_started: { label: 'Not Started', value: 0, color: '#dc3545' },
        reviewing: { label: 'Reviewing', value: 2, color: '#ffc107' },
        practicing: { label: 'Practicing', value: 5, color: '#17a2b8' },
        confident: { label: 'Confident', value: 8, color: '#28a745' },
        mastered: { label: 'Mastered', value: 10, color: '#6f42c1' }
    };
    const statusOrder = ['not_started', 'reviewing', 'practicing', 'confident', 'mastered'];

    // Industrial Biotechnology Syllabus (Structure only, content assumed loaded)
    // Hierarchical Syllabus Configuration
    // Syllabus configuration will be loaded asynchronously
    let syllabusConfig = null;
    let allTopics = [];
    // Syllabus configuration will be loaded from subject.json

    // Helper function to recursively get all topics/sub-topics with IDs
    function getAllTopicsRecursive(items) {
        let topics = [];
        items.forEach(item => {
            if (item.topicId) { // It's a topic or sub-topic
                // Store enough info for progress tracking and scoring
                topics.push({ id: item.topicId, name: item.topicName });
            }
            // Recurse through subTopics or topics within units/subjects
            if (item.subTopics) {
                topics = topics.concat(getAllTopicsRecursive(item.subTopics));
            } else if (item.topics) { // For units
                topics = topics.concat(getAllTopicsRecursive(item.topics));
            } else if (item.units) { // For subjects
                 topics = topics.concat(getAllTopicsRecursive(item.units));
            }
        });
        return topics;
    }

    // allTopics will be calculated after syllabusConfig is loaded

    const LOCAL_STORAGE_PROGRESS_KEY_PREFIX = 'studyTrackPro_progress_'; // More generic prefix
    // NOTE: To fully reset progress after clearing server data (leaderboard.json, etc.),
    // you may need to manually clear local storage in your browser's developer tools.
    // Search for keys starting with 'studyTrackPro_progress_'.

    // --- Global State ---
    let currentUser = null; // Store username of logged-in user
    let userProgress = {}; // Store progress locally { topicId: statusKey }
    let currentLeaderboard = [];
    let unitProgressChartInstance = null;
    let statusDistributionChartInstance = null;
    let currentView = 'dashboard';
    // let currentUnitIndex = 0; // No longer needed with hierarchical view

    // --- DOM Elements ---
    const authModal = document.getElementById('auth-modal');
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const loginIdentifierInput = document.getElementById('login-identifier');
    const loginPasswordInput = document.getElementById('login-password');
    const loginButton = document.getElementById('login-button');
    const loginError = document.getElementById('login-error');
    const showRegisterButton = document.getElementById('show-register-form');
    const registerUsernameInput = document.getElementById('register-username');
    const registerEmailInput = document.getElementById('register-email');
    const registerPasswordInput = document.getElementById('register-password');
    const registerConfirmPasswordInput = document.getElementById('register-confirm-password');
    const registerButton = document.getElementById('register-button');
    const registerError = document.getElementById('register-error');
    const registerSuccess = document.getElementById('register-success'); // Success message element
    const showLoginButton = document.getElementById('show-login-form');
    const appContainer = document.getElementById('app-container');
    const displayUsername = document.getElementById('display-username');
    const logoutButton = document.getElementById('logout-button');
    const topNav = document.querySelector('.top-nav');
    const mainContent = document.getElementById('main-content');
    const views = mainContent.querySelectorAll('.view');
    const topicsCompleted = document.getElementById('topics-completed');
    const currentScore = document.getElementById('current-score');
    const userRank = document.getElementById('user-rank');
    const unitProgressChartCanvas = document.getElementById('unitProgressChart');
    const statusDistributionChartCanvas = document.getElementById('statusDistributionChart');
    // const unitNav = document.querySelector('.unit-nav'); // Removed unit navigation
    const topicListContainer = document.getElementById('topic-list-container');
    const leaderboardBody = document.getElementById('leaderboard-body');
    const refreshLeaderboardButton = document.getElementById('refresh-leaderboard');

    // --- Syllabus Loading ---
    async function loadSyllabusConfig() {
        try {
            // Fetch syllabus from the API endpoint
            const response = await fetch(API_URL + '?action=get_syllabus');
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            console.log(`Syllabus fetch response status: ${response.status} ${response.statusText}`); // Log status

            const data = await response.json(); // Attempt to parse JSON from API

            if (data.status === 'success' && data.syllabus) {
                 console.log("Syllabus configuration loaded successfully via API:", data.syllabus); // Log parsed data
                 return data.syllabus; // Return the syllabus array
            } else {
                 // Handle cases where API returns success=false or syllabus is missing
                 const errorMessage = data.message || 'API did not return syllabus data successfully.';
                 console.error('Error loading syllabus via API:', errorMessage, data);
                 throw new Error(errorMessage);
            }
        } catch (error) {
            // Log specific error type (e.g., network error vs. JSON parse error)
            console.error(`Error loading/parsing syllabus configuration via API:`, error);
            // Re-throw error to be caught by the main initialization block
            throw error;
        }
    }

    // --- Initialization (within async DOMContentLoaded) ---
    console.log("DOM loaded. Initializing app...");
    try {
        syllabusConfig = await loadSyllabusConfig(); // Await the fetch

        if (!syllabusConfig) {
             console.error("Syllabus config failed to load. Cannot initialize fully.");
             // Display an error message to the user? You might want a dedicated UI element.
             showLoginUI(); // Show login as a fallback, or a dedicated error state
             return; // Stop initialization
        }

        // Calculate allTopics only after syllabusConfig is successfully loaded
        allTopics = getAllTopicsRecursive(syllabusConfig);
        console.log("Syllabus processed, proceeding with init...");

        // Setup listeners and check session *after* syllabus is ready
        setupEventListeners();
        await checkSession(); // Check for active session

    } catch (error) {
        console.error("Initialization failed:", error);
        // Display error to user
        showLoginUI(); // Fallback to login UI if essential init fails
    }
    // --- End Initialization ---
    function setupEventListeners() {
        // Auth Modal Switching
        showRegisterButton.addEventListener('click', () => switchAuthForm('register'));
        showLoginButton.addEventListener('click', () => switchAuthForm('login'));

        // Auth Form Submissions
        loginButton.addEventListener('click', handleLogin);
        registerButton.addEventListener('click', handleRegister);
        loginPasswordInput.addEventListener('keypress', (e) => { if (e.key === 'Enter') handleLogin(); });
        registerConfirmPasswordInput.addEventListener('keypress', (e) => { if (e.key === 'Enter') handleRegister(); }); // Also on confirm pass

        // App Listeners
        logoutButton.addEventListener('click', handleLogout);
        refreshLeaderboardButton.addEventListener('click', fetchLeaderboard);
        topNav.addEventListener('click', handleTopNavClick);
        // unitNav.addEventListener('click', handleUnitNavClick); // Removed unit navigation listener
        topicListContainer.addEventListener('click', handleTopicStatusClick);
    }

    // --- Authentication ---

    function switchAuthForm(formToShow) {
        loginError.textContent = ''; // Clear errors on switch
        registerError.textContent = '';
        registerSuccess.textContent = ''; // Clear success message
        if (formToShow === 'register') {
            loginForm.classList.remove('active');
            registerForm.classList.add('active');
        } else {
            registerForm.classList.remove('active');
            loginForm.classList.add('active');
        }
    }

    async function handleRegister() {
        registerError.textContent = ''; // Clear previous errors
        registerSuccess.textContent = ''; // Clear previous success message
        const username = registerUsernameInput.value.trim();
        const email = registerEmailInput.value.trim();
        const password = registerPasswordInput.value;
        const confirmPassword = registerConfirmPasswordInput.value;

        // Basic frontend validation (more robust validation on backend)
        if (!username || !email || !password || !confirmPassword) {
            registerError.textContent = 'Please fill in all fields.'; return;
        }
        if (password !== confirmPassword) {
            registerError.textContent = 'Passwords do not match.'; return;
        }
        if (password.length < 6) {
             registerError.textContent = 'Password must be at least 6 characters.'; return;
        }
         if (username.length < 3) {
             registerError.textContent = 'Username must be at least 3 characters.'; return;
        }

        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'register', username, email, password, confirmPassword })
            });
            const data = await response.json();

            if (data.status === 'success') {
                registerSuccess.textContent = data.message + " Please login."; // Show success message
                // Clear form and switch to login
                registerUsernameInput.value = '';
                registerEmailInput.value = '';
                registerPasswordInput.value = '';
                registerConfirmPasswordInput.value = '';
                setTimeout(() => switchAuthForm('login'), 2000); // Switch after 2 seconds
            } else {
                registerError.textContent = data.message || 'Registration failed.';
            }
        } catch (error) {
            console.error('Registration error:', error);
            registerError.textContent = 'An error occurred during registration.';
        }
    }

    async function handleLogin() {
        loginError.textContent = ''; // Clear previous errors
        const loginIdentifier = loginIdentifierInput.value.trim();
        const password = loginPasswordInput.value;

        if (!loginIdentifier || !password) {
            loginError.textContent = 'Please enter username/email and password.'; return;
        }

        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'login', loginIdentifier, password })
            });
            const data = await response.json();

            if (data.status === 'success') {
                currentUser = data.username; // Set current user from response
                loadUserProgress(); // Load progress for the logged-in user
                showAppUI(); // Show the main application UI
                switchView('dashboard'); // Go to dashboard after login
            } else {
                loginError.textContent = data.message || 'Login failed.';
            }
        } catch (error) {
            console.error('Login error:', error);
            loginError.textContent = 'An error occurred during login.';
        }
    }

    async function handleLogout() {
        try {
            // No need to wait for response if optimistic UI update is okay
            fetch(API_URL + '?action=logout'); // Send logout request (can be GET or POST)
        } catch (error) {
            console.error('Logout error:', error); // Log error but proceed with UI logout
        } finally {
            currentUser = null;
            userProgress = {};
            destroyCharts();
            showLoginUI(); // Show the login modal
        }
    }

    async function checkSession() {
        try {
            const response = await fetch(API_URL + '?action=check_session');
            const data = await response.json();

            if (data.status === 'success' && data.loggedin) {
                currentUser = data.username;
                loadUserProgress();
                showAppUI();
                switchView(currentView); // Restore last view or default to dashboard
            } else {
                showLoginUI();
            }
        } catch (error) {
            console.error('Session check error:', error);
            showLoginUI(); // Show login on error
        }
    }

    // --- UI State Management ---
    function showLoginUI() {
        authModal.classList.add('visible');
        appContainer.classList.add('hidden');
        switchAuthForm('login'); // Default to login form
        clearDashboardData();
        topicListContainer.innerHTML = '';
        leaderboardBody.innerHTML = '<tr><td colspan="3">Login to view</td></tr>';
    }

    function showAppUI() {
        authModal.classList.remove('visible');
        appContainer.classList.remove('hidden');
        displayUsername.textContent = currentUser ? currentUser.split(' ')[0] : 'User'; // Show first name or default
    }

    function clearDashboardData() {
        topicsCompleted.textContent = 'N/A';
        currentScore.textContent = '0';
        userRank.textContent = 'N/A';
        clearChartCanvas(unitProgressChartCanvas);
        clearChartCanvas(statusDistributionChartCanvas);
    }

    // --- Navigation Handlers ---
    function handleTopNavClick(event) {
        const button = event.target.closest('.nav-button');
        if (button && !button.classList.contains('active')) {
            switchView(button.dataset.view);
        }
    }

    // function handleUnitNavClick(event) { ... } // Removed unit navigation handler
    function handleTopicStatusClick(event) {
        const clickedButton = event.target.closest('.status-button'); // Find the button itself

        // Check if a status button was actually clicked and it's not already active
        if (clickedButton && !clickedButton.classList.contains('active')) {
            const topicCard = clickedButton.closest('.topic-card'); // Find the parent card
            const topicId = topicCard?.dataset.topicId;
            const newStatus = clickedButton.dataset.statusKey;

            if (topicId && newStatus) {
                updateTopicStatus(topicId, newStatus);
            } else {
                 console.warn("Could not find topicId or newStatus for clicked button:", clickedButton); // Added warning
            }
        }
    }

    // --- View & Unit Switching ---
    function switchView(viewName) {
        console.log("Switching view to:", viewName);
        currentView = viewName;
        topNav.querySelectorAll('.nav-button').forEach(btn => btn.classList.toggle('active', btn.dataset.view === viewName));
        views.forEach(view => view.classList.toggle('active', view.id === `view-${viewName}`));

        if (viewName !== 'dashboard') destroyCharts();

        if (viewName === 'dashboard') updateDashboardUI();
        else if (viewName === 'topics') renderSyllabusView(); // Changed function name for clarity
        else if (viewName === 'leaderboard') fetchLeaderboard();
    }

    // function switchUnit(unitIndex) { ... } // Removed unit switching logic
    // --- State Management (Progress) ---
    function loadUserProgress() {
        // Load progress from local storage, specific to the logged-in user
        const progressKey = LOCAL_STORAGE_PROGRESS_KEY_PREFIX + currentUser;
        const storedProgress = localStorage.getItem(progressKey);
        userProgress = {}; // Reset for new load
        if (storedProgress) {
            try {
                const parsed = JSON.parse(storedProgress);
                if (typeof parsed === 'object' && parsed !== null) userProgress = parsed;
                else console.warn("Invalid stored progress data.");
            } catch (e) { console.error("Error parsing stored progress.", e); }
        }
        // Ensure all topics from the potentially new structure have a default status
        allTopics.forEach(topic => {
            if (!(topic.id in userProgress)) {
                userProgress[topic.id] = 'not_started';
            }
        });
        // Save progress if defaults were added
        saveUserProgress();
    }

    function saveUserProgress() {
        if (!currentUser) return;
        const progressKey = LOCAL_STORAGE_PROGRESS_KEY_PREFIX + currentUser;
        try { localStorage.setItem(progressKey, JSON.stringify(userProgress)); }
        catch (e) { console.error("Error saving user progress:", e); }
    }

    function calculateScore() {
        return allTopics.reduce((score, topic) => score + (statusLevels[userProgress[topic.id] || 'not_started']?.value || 0), 0);
    }

    // --- UI Rendering ---
    function renderSyllabusView() {
        topicListContainer.innerHTML = ''; // Clear previous content
        if (!currentUser) {
            topicListContainer.innerHTML = '<p>Please log in to view the syllabus.</p>';
            return;
        }

        syllabusConfig.forEach(subject => {
            const subjectDiv = document.createElement('div');
            subjectDiv.className = 'subject-container';
            subjectDiv.innerHTML = `<h2 class="subject-title">${escapeHtml(subject.subjectName)}</h2>`;

            subject.units.forEach(unit => {
                const unitDetails = document.createElement('details');
                unitDetails.className = 'unit-details';
                unitDetails.open = false; // Default to closed
                unitDetails.innerHTML = `<summary class="unit-summary">${escapeHtml(unit.unitName)}</summary>`;
                const unitContent = document.createElement('div');
                unitContent.className = 'unit-content';

                unit.topics.forEach(topic => renderTopicItem(topic, unitContent, 0)); // Start rendering topics at level 0

                unitDetails.appendChild(unitContent);
                subjectDiv.appendChild(unitDetails);
            });

            topicListContainer.appendChild(subjectDiv);
        });
    }

    // Recursive function to render topic/sub-topic items
    function renderTopicItem(topic, parentElement, level) {
        const currentStatus = userProgress[topic.topicId] || 'not_started';
        const topicDiv = document.createElement('div');
        topicDiv.className = `topic-card topic-level-${level}`;
        topicDiv.dataset.topicId = topic.topicId;

        topicDiv.innerHTML = `
            <div class="topic-header">
                <span class="topic-name">${escapeHtml(topic.topicName)}</span>
            </div>
            <div class="topic-controls">
                ${statusOrder.map(key => `
                    <button class="status-button ${currentStatus === key ? 'active' : ''}" data-status-key="${key}" title="${statusLevels[key].label}"
                            style="background-color: ${currentStatus === key ? statusLevels[key].color : '#f0f0f0'}; color: ${currentStatus === key ? 'white' : '#555'}; border-color: ${currentStatus === key ? statusLevels[key].color : 'var(--card-border)'};">
                        ${statusLevels[key].label}
                    </button>`).join('')}
            </div>
        `;
        parentElement.appendChild(topicDiv);

        // Render sub-topics recursively
        if (topic.subTopics && topic.subTopics.length > 0) {
            const subTopicContainer = document.createElement('div');
            subTopicContainer.className = 'sub-topic-container';
            topic.subTopics.forEach(subTopic => renderTopicItem(subTopic, subTopicContainer, level + 1));
            // Append sub-topics indented under the parent topic card
            topicDiv.appendChild(subTopicContainer);
        }
    }

    function updateDashboardUI() {
        if (!currentUser) return;
        const score = calculateScore();

        const masteryCount = Object.values(userProgress).filter(status => status === 'mastered').length;
        topicsCompleted.textContent = `${masteryCount} / ${allTopics.length} Mastered`;
        currentScore.textContent = score;
        updateUserRankDisplay();
        renderDashboardCharts();
    }

    function renderLeaderboard(leaderboard) {
        leaderboardBody.innerHTML = '';
        if (!leaderboard || leaderboard.length === 0) { leaderboardBody.innerHTML = '<tr><td colspan="3">Leaderboard is empty.</td></tr>'; return; }
        leaderboard.sort((a, b) => (b.score ?? 0) - (a.score ?? 0));
        leaderboard.forEach((entry, index) => {
            const rank = index + 1; const tr = document.createElement('tr');
            tr.innerHTML = `<td>${rank}</td><td>${escapeHtml(entry.username)}</td><td>${entry.score ?? 0}</td>`;
            if (currentUser && entry.username.toLowerCase() === currentUser.toLowerCase()) tr.classList.add('current-user-row');
            leaderboardBody.appendChild(tr);
        });
        currentLeaderboard = leaderboard;
        updateUserRankDisplay();
    }

    function updateUserRankDisplay() {
        if (!currentUser || currentLeaderboard.length === 0) { userRank.textContent = 'N/A'; return; }
        const userEntryIndex = currentLeaderboard.findIndex(entry => entry.username.toLowerCase() === currentUser.toLowerCase());
        userRank.textContent = (userEntryIndex !== -1) ? `#${userEntryIndex + 1}` : 'Unranked';
    }

    // --- Topic Interaction ---
    async function updateTopicStatus(topicId, newStatus) {
        if (!currentUser || !userProgress.hasOwnProperty(topicId) || !statusLevels[newStatus]) return;
        userProgress[topicId] = newStatus;
        saveUserProgress(); // Save change locally

        // Update button UI in the list
        const card = topicListContainer.querySelector(`.topic-card[data-topic-id="${topicId}"]`);
        if (card) {
            card.querySelectorAll('.status-button').forEach(button => {
                const isActive = button.dataset.statusKey === newStatus;
                button.classList.toggle('active', isActive);
                button.style.backgroundColor = isActive ? statusLevels[newStatus].color : '#f0f0f0';
                button.style.color = isActive ? 'white' : '#555';
                button.style.borderColor = isActive ? statusLevels[newStatus].color : 'var(--card-border)';
            });
        }

        // Update dashboard if active
        if (currentView === 'dashboard') updateDashboardUI();

        // Update backend score (fire and forget is okay for now)
        updateBackendLeaderboard(calculateScore()); // Pass current total score

        // Fetch leaderboard if relevant view is active
        if (currentView === 'leaderboard' || currentView === 'dashboard') fetchLeaderboard();
    }

    // --- Chart Rendering ---
    function destroyCharts() {
        if (unitProgressChartInstance) { unitProgressChartInstance.destroy(); unitProgressChartInstance = null; }
        if (statusDistributionChartInstance) { statusDistributionChartInstance.destroy(); statusDistributionChartInstance = null; }
    }
    function clearChartCanvas(canvas) { if (canvas) { const ctx = canvas.getContext('2d'); ctx.clearRect(0, 0, canvas.width, canvas.height); } }
    function renderDashboardCharts() {
        destroyCharts(); if (!currentUser || !unitProgressChartCanvas || !statusDistributionChartCanvas) return;
        try { /* renderUnitProgressChart(); */ renderStatusDistributionChart(); } // Commented out unit chart call
        catch (error) { console.error("Error rendering charts:", error); }
    }
    // function renderUnitProgressChart() {
    //     // This chart needs rethinking for the new hierarchical structure.
    //     // Maybe show progress per subject or top-level units across subjects.
    //     // For now, it's disabled.
    //     const unitData = syllabusConfig.map(subject => {
    //         // Example: Calculate progress per subject (needs refinement)
    //         let subjectTopics = [];
    //         subject.units.forEach(unit => {
    //             unit.topics.forEach(topic => {
    //                 subjectTopics.push(topic);
    //                 if(topic.subTopics) subjectTopics = subjectTopics.concat(topic.subTopics);
    //             });
    //         });
    //         const score = subjectTopics.reduce((s, t) => s + (statusLevels[userProgress[t.topicId] || 'not_started']?.value || 0), 0);
    //         const maxScore = subjectTopics.length * statusLevels.mastered.value;
    //         const progress = maxScore > 0 ? (score / maxScore) * 100 : 0;
    //         return { name: subject.subjectName, progress };
    //     });
    //     // const ctx = unitProgressChartCanvas.getContext('2d');
    //     // unitProgressChartInstance = new Chart(ctx, { ... });
    // }
    function renderStatusDistributionChart() {
        const counts = statusOrder.map(key => ({ status: statusLevels[key].label, count: 0, color: statusLevels[key].color }));
        allTopics.forEach(topic => { const idx = statusOrder.indexOf(userProgress[topic.id] || 'not_started'); if (idx !== -1) counts[idx].count++; });
        const filtered = counts.filter(item => item.count > 0);
        const ctx = statusDistributionChartCanvas.getContext('2d');
        statusDistributionChartInstance = new Chart(ctx, { type: 'doughnut', data: { labels: filtered.map(i => i.status), datasets: [{ label: 'Topic Status', data: filtered.map(i => i.count), backgroundColor: filtered.map(i => i.color), hoverOffset: 4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: c => { let t = c.dataset.data.reduce((a, b) => a + b, 0); let p = t > 0 ? ((c.parsed / t) * 100).toFixed(1) + '%' : '0%'; return `${c.label}: ${c.parsed} (${p})`; } } } } } });
    }

    // --- API Interaction ---
    async function fetchLeaderboard() {
        if (currentView !== 'leaderboard' && currentView !== 'dashboard') return;
        console.log("Fetching leaderboard...");
        if (currentView === 'leaderboard') leaderboardBody.innerHTML = '<tr><td colspan="3"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
        try {
            const response = await fetch(`${API_URL}?action=get_leaderboard&t=${Date.now()}`);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const data = await response.json();
            if (data.status === 'success' && Array.isArray(data.leaderboard)) renderLeaderboard(data.leaderboard);
            else throw new Error(data.message || 'Invalid leaderboard data');
        } catch (error) {
            console.error('Error fetching leaderboard:', error);
            if (currentView === 'leaderboard') leaderboardBody.innerHTML = `<tr><td colspan="3">Error loading: ${error.message}</td></tr>`;
            currentLeaderboard = []; updateUserRankDisplay();
        }
    }

    // Modified: No longer needs username passed, uses session on backend
    async function updateBackendLeaderboard(score) {
        if (!currentUser) return; // Don't update if not logged in
        console.log(`Updating backend score to ${score}`);
        try {
            const response = await fetch(API_URL, { // Use POST for update
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update_score', score: score }), // Send only score
            });
            if (!response.ok) {
                 let errorMsg = `HTTP error! status: ${response.status}`;
                 try { const d = await response.json(); errorMsg = d.message || errorMsg; } catch (e) {}
                 throw new Error(errorMsg);
            }
            const data = await response.json();
            if (data.status !== 'success') throw new Error(data.message || 'Failed to update score');
            console.log("Backend score update successful.");
        } catch (error) {
            console.error('Error updating backend leaderboard:', error);
        }
    }

    // --- Utilities ---
    function escapeHtml(unsafe) {
        if (typeof unsafe !== 'string') return '';
        return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    // --- Start the application ---
    // The initialization logic is already handled within the async DOMContentLoaded listener above.
});