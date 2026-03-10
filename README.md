# SimpleChat - Real-Time Chat Application

A real-time chat application built with Laravel (backend) and Next.js (frontend), using Azure Web PubSub for WebSocket messaging.

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![Laravel](https://img.shields.io/badge/Laravel-10.x-red.svg)
![Next.js](https://img.shields.io/badge/Next.js-14.x-black.svg)
![Azure](https://img.shields.io/badge/Azure-Web%20PubSub-blue.svg)

## Features

- ✅ Real-time messaging with Azure Web PubSub (Socket.IO mode)
- ✅ Polling fallback for when WebSocket isn't configured
- ✅ Optimistic UI updates
- ✅ User authentication with Laravel Sanctum
- ✅ Responsive design with Tailwind CSS
- ✅ TypeScript for type safety
- ✅ Automatic reconnection with token refresh

## Tech Stack

### Backend
- **Laravel 10.x** - PHP framework
- **Laravel Sanctum** - Authentication
- **Azure Web PubSub** - WebSocket service
- **MySQL/SQLite** - Database (configurable)

### Frontend
- **Next.js 14.x** - React framework with App Router
- **TypeScript** - Type safety
- **Tailwind CSS** - Styling
- **Socket.IO Client** - WebSocket client
- **Azure Web PubSub Client** - WebSocket SDK

## Architecture

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────────┐
│   Frontend      │     │    Backend      │     │  Azure Web PubSub   │
│   (Next.js)     │────▶│   (Laravel)     │────▶│  (WebSocket Hub)    │
│                 │     │                 │     │                     │
│  - Chat UI      │     │  - REST API     │     │  - Message Broker   │
│  - Socket.IO    │     │  - Auth (Sanctum)│     │  - Group Management │
└─────────────────┘     └─────────────────┘     └─────────────────────┘
        │                       │
        │                       │
        ▼                       ▼
   Browser/Web            Database (MySQL)
```

## Quick Start

### Prerequisites

- PHP 8.1+
- Composer
- Node.js 18+
- npm or yarn
- Azure Web PubSub resource (or use polling mode)

### 1. Clone the Repository

```bash
git clone https://github.com/YOUR_USERNAME/simplechat.git
cd simplechat
```

### 2. Backend Setup

```bash
cd backend

# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Configure your .env file
```

Edit `backend/.env`:

```env
APP_NAME=SimpleChat
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=sqlite
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=simplechat
# DB_USERNAME=root
# DB_PASSWORD=

# Azure Web PubSub Configuration
AZURE_WEBPUBSUB_ENDPOINT=Endpoint=https://YOUR_RESOURCE.webpubsub.azure.com;AccessKey=YOUR_KEY;Version=1.0;
AZURE_WEBPUBSUB_HUB=chat

# Frontend URL (for CORS)
SANCTUM_STATEFUL_DOMAINS=localhost:3000
SANCTUM_TOKEN_DOMAIN=localhost:3000
SESSION_DOMAIN=localhost
```

```bash
# Run migrations
php artisan migrate

# Start Laravel server
php artisan serve
```

The backend will run on `http://localhost:8000`

### 3. Frontend Setup

```bash
cd frontend

# Install dependencies
npm install

# Create .env.local file
cat > .env.local << EOF
NEXT_PUBLIC_API_URL=http://localhost:8000
EOF

# Start development server
npm run dev
```

The frontend will run on `http://localhost:3000`

### 4. Create Test Users

```bash
# In the backend directory
php artisan tinker

# Create user 1
>>> User::create(['name' => 'Test User', 'email' => 'test@example.com', 'password' => bcrypt('password')])

# Create user 2
>>> User::create(['name' => 'Test User 2', 'email' => 'testuser2@example.com', 'password' => bcrypt('password')])

>>> exit
```

### 5. Test the Application

1. Open two browser windows (or use incognito mode)
2. Go to `http://localhost:3000` in both
3. Login as different users:
   - Browser 1: `test@example.com` / `password`
   - Browser 2: `testuser2@example.com` / `password`
4. Send a message from one browser - it should appear in both!

## Azure Web PubSub Configuration

### Option 1: Full Real-Time Mode (Recommended)

For true real-time messaging, configure Azure Event Handler:

1. Go to [Azure Portal](https://portal.azure.com)
2. Find your Web PubSub resource
3. Go to **Settings** → **Event Handler**
4. Add a new handler:
   - **Name**: `simplechat-handler`
   - **URL Template**: `http://localhost:8000/api/webpubsub/events` (local) or your production URL
   - **System Events**: Check `connected`, `disconnected`
5. Click **Create/Save**

See [docs/AZURE_EVENT_HANDLER_SETUP.md](docs/AZURE_EVENT_HANDLER_SETUP.md) for detailed instructions.

### Option 2: Polling Mode (No Azure Config Needed)

The app includes a polling fallback that fetches new messages every 2 seconds. This works without any Azure configuration but has a slight delay (~2 seconds).

**Note**: Polling is enabled by default. The app will use real-time WebSocket if the event handler is configured, otherwise it falls back to polling.

## API Endpoints

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/register` | Register a new user |
| POST | `/api/login` | Login user |
| POST | `/api/logout` | Logout user |
| GET | `/api/user` | Get current user |

### Messages

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/messages` | Get recent messages |
| GET | `/api/messages?after=TIMESTAMP` | Get messages after timestamp |
| POST | `/api/messages/send` | Send a new message |

### WebSocket

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/negotiate` | Get WebSocket connection credentials |

### Event Handler (Azure)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/webpubsub/events` | Handle Azure events |

## Project Structure

```
simplechat/
├── backend/                    # Laravel backend
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/    # API controllers
│   │   │   └── Middleware/     # Custom middleware
│   │   ├── Models/             # Eloquent models
│   │   └── Services/           # Business logic
│   │       ├── AzurePubSubConfig.php
│   │       ├── AzurePubSubPublisher.php
│   │       └── AzurePubSubTokenService.php
│   ├── database/
│   │   └── migrations/         # Database migrations
│   ├── routes/                 # API routes
│   └── storage/                # Logs, uploads, etc.
├── frontend/                   # Next.js frontend
│   ├── app/                    # App Router pages
│   ├── components/             # React components
│   │   └── ChatWindow.tsx      # Main chat component
│   ├── hooks/                  # Custom React hooks
│   │   └── useSocket.ts        # Socket connection hook
│   ├── lib/                    # Utilities
│   │   └── pubsub/             # Azure Web PubSub client
│   │       ├── negotiate.ts
│   │       └── socketManager.ts
│   └── public/                 # Static assets
└── docs/                       # Documentation
    └── AZURE_EVENT_HANDLER_SETUP.md
```

## Development

### Backend Commands

```bash
cd backend

# Run development server
php artisan serve

# Run migrations
php artisan migrate

# Create new migration
php artisan make:migration create_xxx_table

# Run tests
php artisan test

# View logs
tail -f storage/logs/laravel.log
```

### Frontend Commands

```bash
cd frontend

# Run development server
npm run dev

# Build for production
npm run build

# Run tests
npm test

# Run linting
npm run lint
```

## Troubleshooting

### Messages not appearing in real-time

1. **Check polling fallback**: Open browser console and look for polling logs
2. **Verify Azure config**: Check `backend/.env` for correct Azure credentials
3. **Check backend logs**: `tail -f backend/storage/logs/laravel.log`
4. **Test WebSocket**: Look for `[ChatWindow] Socket connected` in browser console

### Authentication errors

1. **Verify CORS settings**: Ensure `SANCTUM_STATEFUL_DOMAINS` includes your frontend URL
2. **Check session config**: Ensure `SESSION_DOMAIN` is set correctly
3. **Clear browser cookies**: Sometimes old sessions cause issues

### Azure connection errors

1. **Verify credentials**: Check `AZURE_WEBPUBSUB_ENDPOINT` in `.env`
2. **Test connectivity**: Use the test script in the project root
3. **Check hub name**: Ensure `AZURE_WEBPUBSUB_HUB` matches your Azure configuration

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is open-sourced software licensed under the [MIT license](LICENSE).

## Support

For issues, questions, or contributions, please visit the [GitHub repository](https://github.com/YOUR_USERNAME/simplechat/issues).

---

**Made with ❤️ using Laravel, Next.js, and Azure Web PubSub**
