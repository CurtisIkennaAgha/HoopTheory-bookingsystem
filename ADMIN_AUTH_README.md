# Admin Authentication System

## Overview
The admin panel ([admin.html](admin.html)) now includes a login system that protects access to administrative functions. Credentials are stored in environment variables and validated on the frontend.

## Features
- ✅ Login modal that appears on page load
- ✅ Username and password authentication
- ✅ "Remember me" checkbox for persistent sessions
- ✅ Environment variable-based credential management
- ✅ Logout functionality with session clearing
- ✅ Modal cannot be dismissed without logging in

## Setup Instructions

### 1. Environment Variables
Set the following environment variables in your hosting environment or locally:

**Windows (PowerShell):**
```powershell
$env:ADMIN_USERNAME='bao@hooptheory.co.uk'
$env:ADMIN_PASSWORD='Dangbongro.72'
```

**Windows (Command Prompt):**
```cmd
set ADMIN_USERNAME=bao@hooptheory.co.uk
set ADMIN_PASSWORD=Dangbongro.72
```

**Linux/Mac:**
```bash
export ADMIN_USERNAME='bao@hooptheory.co.uk'
export ADMIN_PASSWORD='Dangbongro.72'
```

### 2. PHP Configuration
The credentials are served via [php/getAdminConfig.php](php/getAdminConfig.php), which reads from environment variables using `getenv()`.

**Default credentials (if environment variables not set):**
- Username: `bao@hooptheory.co.uk`
- Password: `Dangbongro.72`

### 3. Hosting Environment
For production hosting (e.g., cPanel, Plesk, or cloud hosting), add the environment variables through your hosting control panel's environment variable settings.

## How It Works

### Login Flow
1. On page load, JavaScript checks `localStorage.getItem("adminAuthed")`
2. If not authenticated, the login modal is displayed
3. Admin credentials are fetched from `php/getAdminConfig.php`
4. User enters username and password
5. If correct AND "Remember me" is checked → `localStorage.setItem("adminAuthed", "true")`
6. If correct WITHOUT "Remember me" → Session-only authentication (cleared on browser close)
7. If incorrect → Error message displayed

### Logout Flow
1. Click the logout button (top-right corner, visible after login)
2. `localStorage.removeItem("adminAuthed")` is called
3. Page reloads, forcing re-authentication

### Security Considerations
⚠️ **Important:** This is a **frontend-only** authentication system suitable for local/internal use. For production environments with sensitive data:

- Consider adding backend PHP session validation
- Use HTTPS to encrypt credentials in transit
- Implement rate limiting to prevent brute force attacks
- Consider JWT tokens or server-side session management
- Add IP whitelisting if accessing from known locations

## Usage

### Logging In
1. Open [admin.html](admin.html)
2. Enter credentials in the login modal
3. Check "Remember me" if you want persistent authentication
4. Click "Login"

### Logging Out
- Click the red "Logout" button in the top-right corner
- OR call `logoutAdmin()` from the browser console

### Changing Credentials
Update the environment variables and restart your PHP server.

## Files Modified
- [admin.html](admin.html) - Added login modal HTML, CSS, and JavaScript
- [php/getAdminConfig.php](php/getAdminConfig.php) - New endpoint to serve credentials
- [.env.example](.env.example) - Example environment variable configuration

## Troubleshooting

**Login modal doesn't appear:**
- Check browser console for JavaScript errors
- Verify `admin.html` loaded correctly

**Credentials rejected:**
- Verify environment variables are set correctly
- Check PHP server is running
- Inspect `php/getAdminConfig.php` response in Network tab

**"Remember me" not working:**
- Clear browser localStorage and try again
- Check if browser allows localStorage
- Verify not in private/incognito mode

**Logout not working:**
- Clear browser cache and localStorage manually
- Use browser developer tools: `localStorage.removeItem("adminAuthed")`

## Developer Notes
The authentication system is initialized in an immediately-invoked async function at the top of the `<script>` section in [admin.html](admin.html). The logout function is exposed globally as `window.logoutAdmin()` for programmatic access.
