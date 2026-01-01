# Mini Chatbot Agency (ChatBot Hub)

ChatBot Hub is a lightweight AI chatbot management platform: users can register, create multiple personalized chatbots (each with its own “personality”/system prompt), chat with them via OpenAI models, and generate images via DALL‑E.

The project is intentionally dependency-free (vanilla JS + PHP + JSON files) and is designed to run from **any subdirectory** thanks to **relative paths**.

---


## 1. Project Overview

**ChatBot Hub** is an AI chatbot management platform.

- **Key value proposition:** create, manage, and interact with multiple AI chatbots powered by OpenAI.
- **Target users:** anyone who wants to manage multiple personalized chatbots (personal use, small teams, demos, learning projects).

The UI is in **Italian** (messages, buttons, alerts), while the code and API are straightforward and easy to adapt.

---

## 2. Features

- User authentication with mail notification, OTP verification and password reset
- Create and manage multiple chatbots with custom personalities
- Real-time conversation with OpenAI GPT models
- Image generation with DALL-E integration
- Tiered subscription plans (Free, Basic, Premium - IMPORTANT: payment to be implemented with Stripe)
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
- **Security:** input sanitization, file locking, CORS protection

---

## 4. Project Structure

mini_chatbot_agency/
├── index.html           # Main application interface 
├── verify.html          # OTP check page
├── reset_password.html  # password reset page
├── api.php              # Backend API (monolithic, all endpoints)
├── data/
│   └── users.json       # User and bot data storage
├── README.md            # This file
└── error.log            # Error logging (auto-generated)

---

## 5. License & Credits

- Built with OpenAI APIs (ChatGPT / Chat Completions and DALL‑E)
- Italian language interface
- JSON file storage for simplicity
- No external dependencies (vanilla JS, PHP built-ins)
