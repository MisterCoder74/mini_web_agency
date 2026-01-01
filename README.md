# Mini Chatbot Agency (ChatBot Hub)

ChatBot Hub is a lightweight AI chatbot management platform: users can register, create multiple personalized chatbots (each with its own “personality”/system prompt), chat with them via OpenAI models, and generate images via DALL‑E.

The project is intentionally dependency-free (vanilla JS + PHP + JSON files) and is designed to run from **any subdirectory** thanks to **relative paths**.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Features](#2-features)
3. [Technology Stack](#3-technology-stack)
4. [Project Structure](#4-project-structure)
5. [Installation & Setup](#5-installation--setup)
6. [Application Architecture](#6-application-architecture)
7. [API Endpoints](#7-api-endpoints)
8. [Data Models](#8-data-models)
9. [Security Features](#9-security-features)
10. [Usage Instructions](#10-usage-instructions)
11. [Development Notes](#11-development-notes)
12. [Conversation Storage Architecture](#12-conversation-storage-architecture)
13. [Troubleshooting](#13-troubleshooting)
14. [License & Credits](#14-license--credits)

---

## 1. Project Overview

**ChatBot Hub** is an AI chatbot management platform.

- **Key value proposition:** create, manage, and interact with multiple AI chatbots powered by OpenAI.
- **Target users:** anyone who wants to manage multiple personalized chatbots (personal use, small teams, demos, learning projects).

The UI is in **Italian** (messages, buttons, alerts), while the code and API are straightforward and easy to adapt.

---

## 2. Features

- User authentication with secure password hashing
- Create and manage multiple chatbots with custom personalities
- Real-time conversation with OpenAI GPT models
- Image generation with DALL-E integration
- Tiered subscription plans (Free, Basic, Premium)
- Usage tracking and limits per plan
- Persistent conversation history (server-side storage)
- Context memory management (**last 50 messages displayed** in the UI)
- Full conversation history accessible from the server (`getBot`)
- Client-side API key storage (browser `localStorage`)

---

## 3. Technology Stack

- **Frontend:** Vanilla JavaScript (ES6), HTML5, inline CSS
- **Backend:** PHP 8.3+
- **Storage:** JSON files (`data/users.json`)
- **External APIs:**
  - OpenAI **Chat Completions** (`/v1/chat/completions`)
  - OpenAI **Images** (DALL‑E 3 via `/v1/images/generations`)
- **Security:** password hashing, input sanitization, file locking, CORS protection

---

## 4. Project Structure

```text
mini_chatbot_agency/
├── index.html           # Main application interface (SPA)
├── api.php              # Backend API (monolithic, all endpoints)
├── data/
│   └── users.json       # User and bot data storage
├── README.md            # This file
└── error.log            # Error logging (auto-generated)
```

---

## 5. Installation & Setup

### Prerequisites

- PHP **8.3** or higher
- Modern web browser with JavaScript enabled
- OpenAI API key: https://platform.openai.com/api-keys

### Installation Steps

1. Clone or download the repository
2. Place the project in a web server directory (works in any subdirectory; uses relative paths)
3. Ensure `data/` directory is writable by PHP
   - Example:
     ```bash
     chmod 755 data
     ```
4. Start the PHP development server:
   ```bash
   php -S localhost:8000
   ```
5. Open the browser:
   - `http://localhost:8000`

### Non-Root Hosting

The application uses **relative paths** and works when deployed in any subdirectory:

- All API calls use `./api.php` (relative to `index.html`)
- All data paths use `./data/` (relative)
- Works on shared hosting or nested project directories

---

## 6. Application Architecture

### High-level architecture

- **`index.html` (Single Page App):**
  - Renders login/register, dashboard, bot management, chat UI, settings.
  - Stores the OpenAI API key in `localStorage`.
  - Keeps a small in-memory UI buffer (`contextMemory`) for chat rendering.

- **`api.php` (Monolithic JSON API):**
  - Receives requests via `POST` JSON: `{ action: "...", ... }`.
  - Uses PHP sessions (`$_SESSION['user_id']`) for authentication.
  - Persists state to a JSON file.

- **`data/users.json` (File storage):**
  - Stores users, subscription plan, usage counters, bots, and each bot’s `conversations` array.

### Request flow (chat message)

1. User writes a message in the UI
2. Frontend reads API key from `localStorage`
3. Frontend `fetch()` POSTs to `./api.php` (`action: 'sendMessage'`)
4. Backend validates session + input + plan limits
5. Backend calls OpenAI Chat Completions
6. Backend appends both user and assistant messages to `bot.conversations`
7. Backend persists to `data/users.json` with file locking
8. Frontend renders the assistant reply and keeps only the last 50 messages visible

---

## 7. API Endpoints

**All endpoints** are `POST` requests to `./api.php` with a JSON body.

### Authentication

#### `register`: Create new account

- Payload:
  ```js
  { action: 'register', name, email, password }
  ```
- Returns:
  ```js
  { success, message }
  ```
- Notes:
  - Password must be **≥ 8 characters**
  - Password is hashed with `password_hash()` (bcrypt via `PASSWORD_DEFAULT`)

#### `login`: Authenticate user

- Payload:
  ```js
  { action: 'login', email, password }
  ```
- Returns:
  ```js
  { success, user }
  ```
- Notes:
  - Creates a PHP session
  - Returns the user object (password is never returned)

#### `logout`: Destroy session

- Payload:
  ```js
  { action: 'logout' }
  ```
- Returns:
  ```js
  { success }
  ```

#### `checkAuth`: Verify session and get user data

- Payload:
  ```js
  { action: 'checkAuth' }
  ```
- Returns:
  ```js
  { success, user } // or { success: false }
  ```

### Bot Management

#### `createBot`: Create new chatbot

- Payload:
  ```js
  { action: 'createBot', name, personality, model }
  ```
- Returns:
  ```js
  { success, bot }
  ```
- Notes:
  - Inputs are sanitized (`trim` + `htmlspecialchars`)
  - Enforced limits: name (max **100** chars), personality (max **5000** chars)
  - Model choices are validated server-side. The UI currently includes `gpt-3.5-turbo` and `gpt-4.1-nano`.

#### `getBots`: List all user bots

- Payload:
  ```js
  { action: 'getBots' }
  ```
- Returns:
  ```js
  { success, bots: [] }
  ```

#### `getBot`: Retrieve one bot (with full conversation history)

- Payload:
  ```js
  { action: 'getBot', botId }
  ```
- Returns:
  ```js
  { success, bot } // includes full conversations array
  ```

#### `deleteBot`: Remove a bot

- Payload:
  ```js
  { action: 'deleteBot', botId }
  ```
- Returns:
  ```js
  { success }
  ```

### Messaging

#### `sendMessage`: Send message to bot and get response

- Payload:
  ```js
  {
    action: 'sendMessage',
    botId,
    message,
    apiKey,      // retrieved from localStorage and passed by the client
    history: []  // full conversation array from the client (typically current bot.conversations)
  }
  ```

- Returns:
  ```js
  {
    success,
    response,              // assistant message
    usage,                 // updated usage counters (messages/images + reset metadata)
    conversation,          // updated full bot conversation array
    nearLimit,             // boolean (true when close to message limit)
    bot                    // updated bot object
  }
  ```

- Behavior:
  - The server persists the **full** conversation history in `bot.conversations`.
  - The server sends only **recent context** to OpenAI (based on plan history limits) while keeping full history on disk.
  - The UI displays the last **50** messages (`contextMemory`).

### Image Generation

#### `generateImage`: Create an image with DALL-E

- Payload:
  ```js
  { action: 'generateImage', botId, prompt, apiKey }
  ```

- Returns:
  ```js
  {
    success,
    url,        // image URL (used by the current UI)
    imageUrl,   // alias of url (useful for API clients)
    usage       // updated usage counters
  }
  ```

- Notes:
  - Subject to daily image limits per plan.

### Subscription

#### `upgradePlan`: Change subscription tier

- Payload:
  ```js
  { action: 'upgradePlan', plan }
  ```

- Plans:
  - `basic`
  - `premium`

- Returns:
  ```js
  { success, message }
  ```

---

## 8. Data Models

### User Object (`data/users.json`)

```json
{
  "id": "unique_id",
  "name": "User Name",
  "email": "user@example.com",
  "password": "$2y$10$hashed_password_hash",
  "plan": "free",
  "usage": {
    "messages": 45,
    "images": 2,
    "lastReset": "2024-01-15",
    "lastMessageReset": "2024-01-15"
  },
  "bots": [
    {
      "id": "bot_id",
      "name": "Bot Name",
      "personality": "You are a helpful assistant...",
      "model": "gpt-3.5-turbo",
      "created_at": "2024-01-15 10:30:00",
      "conversations": [
        {
          "id": "msg_id",
          "role": "user",
          "content": "Hello!",
          "timestamp": "2024-01-15 10:31:00"
        },
        {
          "id": "msg_id_2",
          "role": "assistant",
          "content": "Hi! How can I help?",
          "timestamp": "2024-01-15 10:31:05"
        }
      ]
    }
  ]
}
```

### Client-side context memory (JavaScript)

```js
let contextMemory = [];            // Array of messages shown in the UI
const CONTEXT_MEMORY_LIMIT = 50;   // last 50 messages displayed

// message structure used by the UI:
// { role: 'user'|'assistant', content: '...', id: '...' }
```

---

## 9. Security Features

### Password Security

- Passwords are hashed using PHP `password_hash()` (bcrypt via `PASSWORD_DEFAULT`)
- Passwords are verified using `password_verify()`
- Passwords are never stored or returned in plain text
- Password strength requirement: **minimum 8 characters**

### Input Validation & Sanitization

- All user-controlled strings are sanitized with:
  - `trim()`
  - `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`
- Email validation uses:
  - `filter_var($email, FILTER_VALIDATE_EMAIL)`
  - plus an additional regex guard
- Length limits enforced:
  - bot name: max **100**
  - personality: max **5000**
  - message/prompt: max **10000**
- Role validation for conversation entries (`user`, `assistant`, `system`)

### File Locking (Concurrency Safety)

- All JSON file operations are protected with `flock()`
- Prevents race conditions and data corruption under concurrent requests
- Uses a lock wrapper (`withLock`) to ensure the lock is released even when errors occur

### API Key Management

- API keys are stored in the browser **localStorage** under:
  - `chatbotHubApiKey`
- API keys are **not stored** in `users.json` or any server-side settings
- The client sends the API key with each OpenAI request
- **Important:** use HTTPS in production. Even if the key is stored client-side, it is still transmitted in requests.

### CORS Protection

- CORS is not configured as a wildcard (`*`).
- Requests are intended to be same-origin with the hosted UI.

---

## 10. Usage Instructions

### First Time Setup

1. Register an account (name, email, password **≥ 8 chars**)
2. Login with your credentials
3. Go to **Configurazione** → enter your OpenAI API Key → **Salva**
4. The API key is stored in the browser localStorage (not on the server)

### Creating a Chatbot

1. Click **Crea Nuovo Bot**
2. Enter bot name (max 100 chars)
3. Enter personality/system prompt (max 5000 chars)
4. Select an OpenAI model (currently exposed in the UI: `gpt-3.5-turbo`, `gpt-4.1-nano`)
5. Click **Crea Bot**

### Chatting with a Bot

1. Select a bot from the list
2. Type your message
3. Press Enter or click **Invia**
4. The response is displayed in the chat
5. The UI shows the last **50** messages
6. The server persists the full conversation history

### Context Memory Behavior

- The UI shows only the last 50 messages (`contextMemory`)
- The server stores the full history (`bot.conversations`)
- Older messages are not shown in the UI buffer, but remain in `users.json`

### Managing the API Key

1. Go to the **Configurazione** panel
2. Paste your OpenAI API key in the API Key field
3. Click **Salva**
4. The key is stored in browser `localStorage`
5. Clear the field and save again to remove the key

### Plan Limits

**Free Plan** (default):
- 100 messages/month
- 3 images/day

**Basic Plan**:
- 5,000 messages/month
- 10 images/day

**Premium Plan**:
- Unlimited messages
- Unlimited images

Resets:
- Image usage resets daily
- Message usage resets monthly

---

## 11. Development Notes

### Running locally

```bash
php -S localhost:8000
```

### Testing API endpoints

Use curl/Postman to POST to `./api.php`:

```bash
curl -X POST http://localhost:8000/api.php \
  -H "Content-Type: application/json" \
  -d '{"action":"register","name":"Test","email":"test@example.com","password":"password123"}'
```

### Error logging

- PHP errors are logged to `error.log`
- If API requests fail, check `error.log` for server-side details

### File permissions

- `data/` must be writable
- `data/users.json` is created automatically if missing

### Relative path architecture

- All paths are relative (no hardcoded absolute paths)
- Designed to work on shared hosting and in subdirectories

---

## 12. Conversation Storage Architecture

### Server-side persistence

- Full conversation is stored in `bot.conversations`
- Each message is stored as:
  - `{ id, role, content, timestamp }`
- Saved to `data/users.json` after each message
- Full history can be retrieved via `getBot`

### Client-side context memory

- The UI maintains `contextMemory` with a maximum of 50 messages
- This reduces UI clutter for long conversations
- Full history remains on the server

### Message flow

1. User types message → added to `contextMemory`
2. Client sends request with: `botId`, `message`, `apiKey`, and a copy of conversation history
3. Server calls OpenAI using personality + conversation context
4. Server receives response
5. Server persists both user + assistant messages
6. Client renders response; UI keeps only last 50 messages visible

---

## 13. Troubleshooting

### “API Key not configured” / “API Key non configurata”

- Go to **Configurazione**
- Enter your OpenAI API key
- Click **Salva**

### “Limite messaggi raggiunto”

- Free: 100 messages/month
- Upgrade to Basic/Premium or wait for the monthly reset

### “Limite immagini raggiunto”

- Free: 3 images/day (resets daily)
- Upgrade to Basic/Premium for more

### API errors or connection issues

- Check `error.log`
- Verify the API key is valid and has billing/permissions
- Verify you’re running PHP 8.3+

### Password issues

- Minimum 8 characters required
- Passwords are hashed and cannot be recovered

---

## 14. License & Credits

- Built with OpenAI APIs (ChatGPT / Chat Completions and DALL‑E)
- Italian language interface
- JSON file storage for simplicity
- No external dependencies (vanilla JS, PHP built-ins)
