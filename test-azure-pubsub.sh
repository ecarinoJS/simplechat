#!/bin/bash

# Azure Web PubSub Socket.IO End-to-End Test Script
# This script tests the complete Azure Web PubSub functionality

# Don't exit on error, continue running all tests

API_URL="http://localhost:8000"
FRONTEND_URL="http://localhost:3000"

echo "=========================================="
echo "Azure Web PubSub Socket.IO Test"
echo "=========================================="
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test counter
TESTS_PASSED=0
TESTS_FAILED=0

# Helper function to print test results
print_result() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✓ PASSED${NC}: $2"
        ((TESTS_PASSED++))
    else
        echo -e "${RED}✗ FAILED${NC}: $2"
        ((TESTS_FAILED++))
    fi
}

# Test 1: Check if backend is running
echo "Test 1: Checking if backend server is running..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$API_URL" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" != "000" ]; then
    print_result 0 "Backend server is running (HTTP $HTTP_CODE)"
else
    print_result 1 "Backend server is not running"
    exit 1
fi

# Test 2: Check if frontend is running
echo ""
echo "Test 2: Checking if frontend server is running..."
if curl -s -o /dev/null -w "%{http_code}" "$FRONTEND_URL" 2>/dev/null | grep -q "200"; then
    print_result 0 "Frontend server is running"
else
    print_result 1 "Frontend server is not running"
fi

# Test 3: Test User Registration
echo ""
echo "Test 3: Testing user registration..."
TIMESTAMP=$(date +%s)
TEST_EMAIL="testuser_${TIMESTAMP}@example.com"
REGISTER_RESPONSE=$(curl -s -c /tmp/cookies.txt -X POST "$API_URL/register" \
    -H "Content-Type: application/json" \
    -d "{\"name\":\"Test User ${TIMESTAMP}\",\"email\":\"${TEST_EMAIL}\",\"password\":\"password123\",\"password_confirmation\":\"password123\"}" 2>/dev/null || echo '{"error": "request failed"}')

if echo "$REGISTER_RESPONSE" | grep -q '"user"'; then
    USER_ID=$(echo "$REGISTER_RESPONSE" | grep -o '"id":[^,}]*' | head -1 | cut -d':' -f2 | tr -d '" ' || echo "unknown")
    print_result 0 "User registration successful"
    echo "  Response: $REGISTER_RESPONSE"
else
    print_result 1 "User registration failed"
    echo "  Response: $REGISTER_RESPONSE"
fi

# Test 4: Test User Login
echo ""
echo "Test 4: Testing user login..."
LOGIN_RESPONSE=$(curl -s -b /tmp/cookies.txt -c /tmp/cookies.txt -X POST "$API_URL/login" \
    -H "Content-Type: application/json" \
    -d "{\"email\":\"${TEST_EMAIL}\",\"password\":\"password123\"}" 2>/dev/null || echo '{"error": "request failed"}')

if echo "$LOGIN_RESPONSE" | grep -q '"user"'; then
    print_result 0 "User login successful"
    echo "  Response: $LOGIN_RESPONSE"
else
    print_result 1 "User login failed"
    echo "  Response: $LOGIN_RESPONSE"
fi

# Test 5: Test Negotiate Endpoint (Azure Web PubSub Token Generation)
echo ""
echo "Test 5: Testing /api/negotiate endpoint..."
NEGOTIATE_RESPONSE=$(curl -s -b /tmp/cookies.txt -X GET "$API_URL/api/negotiate" \
    -H "Content-Type: application/json" 2>/dev/null || echo '{"error": "request failed"}')

echo "Negotiate Response: $NEGOTIATE_RESPONSE"

if echo "$NEGOTIATE_RESPONSE" | grep -q '"endpoint"' && \
   echo "$NEGOTIATE_RESPONSE" | grep -q '"hub"' && \
   echo "$NEGOTIATE_RESPONSE" | grep -q '"token"' && \
   echo "$NEGOTIATE_RESPONSE" | grep -q '"expires"'; then

    # Extract values for validation
    ENDPOINT=$(echo "$NEGOTIATE_RESPONSE" | grep -o '"endpoint":"[^"]*"' | cut -d'"' -f4)
    HUB=$(echo "$NEGOTIATE_RESPONSE" | grep -o '"hub":"[^"]*"' | cut -d'"' -f4)
    TOKEN=$(echo "$NEGOTIATE_RESPONSE" | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
    EXPIRES=$(echo "$NEGOTIATE_RESPONSE" | grep -o '"expires":[0-9]*' | cut -d':' -f2)

    print_result 0 "Negotiate endpoint working"
    echo "  - Endpoint: $ENDPOINT"
    echo "  - Hub: $HUB"
    echo "  - Token length: ${#TOKEN} chars"
    echo "  - Expires at: $(date -r $EXPIRES 2>/dev/null || echo 'Invalid timestamp')"

    # Validate JWT token format
    TOKEN_PARTS=$(echo "$TOKEN" | tr '.' '\n' | wc -l | tr -d ' ')
    if [ "$TOKEN_PARTS" -eq 3 ]; then
        print_result 0 "Token is valid JWT format (header.payload.signature)"

        # Decode JWT payload (without verification, just for inspection)
        PAYLOAD=$(echo "$TOKEN" | cut -d'.' -f2 | base64 -d 2>/dev/null || echo "decode failed")
        if [ "$PAYLOAD" != "decode failed" ]; then
            echo "  - JWT Payload: $PAYLOAD"
        fi
    else
        print_result 1 "Token is not valid JWT format"
    fi
else
    print_result 1 "Negotiate endpoint failed"
    echo "Response: $NEGOTIATE_RESPONSE"
fi

# Test 6: Verify Azure configuration is loaded
echo ""
echo "Test 6: Verifying Azure configuration..."
AZURE_CONNECTION_STRING=$(grep "AZURE_PUBSUB_CONNECTION_STRING=" /opt/homebrew/var/www/simpleChat/backend/.env 2>/dev/null | cut -d'=' -f2)
if [ -n "$AZURE_CONNECTION_STRING" ]; then
    print_result 0 "Azure connection string configured"
    echo "  - Endpoint: $(echo $AZURE_CONNECTION_STRING | grep -o 'Endpoint=[^;]*' | cut -d'=' -f2)"
    echo "  - Hub: $(grep "AZURE_PUBSUB_HUB=" /opt/homebrew/var/www/simpleChat/backend/.env | cut -d'=' -f2)"
else
    print_result 1 "Azure connection string not configured"
fi

# Test 7: Test sending a message
echo ""
echo "Test 7: Testing message sending via HTTP..."
SEND_MESSAGE_RESPONSE=$(curl -s -b /tmp/cookies.txt -X POST "$API_URL/api/messages/send" \
    -H "Content-Type: application/json" \
    -d '{"content":"Test message from Azure Web PubSub test"}' 2>/dev/null || echo '{"error": "request failed"}')

if echo "$SEND_MESSAGE_RESPONSE" | grep -q '"success":true'; then
    print_result 0 "Message sent successfully"
    echo "  Response: $SEND_MESSAGE_RESPONSE"
else
    print_result 1 "Failed to send message"
    echo "  Response: $SEND_MESSAGE_RESPONSE"
fi

# Test 8: Verify Socket.IO client is installed
echo ""
echo "Test 8: Verifying Socket.IO client installation..."
if [ -f "/opt/homebrew/var/www/simpleChat/frontend/node_modules/socket.io-client/package.json" ]; then
    SOCKET_IO_VERSION=$(grep '"version"' /opt/homebrew/var/www/simpleChat/frontend/node_modules/socket.io-client/package.json | head -1 | cut -d'"' -f4)
    print_result 0 "Socket.IO client installed (v$SOCKET_IO_VERSION)"
else
    print_result 1 "Socket.IO client not installed"
fi

# Summary
echo ""
echo "=========================================="
echo "Test Summary"
echo "=========================================="
echo -e "${GREEN}Passed: $TESTS_PASSED${NC}"
echo -e "${RED}Failed: $TESTS_FAILED${NC}"
echo ""

# Check if backend Laravel logs show any Azure activity
echo "=========================================="
echo "Recent Laravel Log Entries"
echo "=========================================="
if [ -f "/opt/homebrew/var/www/simpleChat/backend/storage/logs/laravel.log" ]; then
    tail -20 /opt/homebrew/var/www/simpleChat/backend/storage/logs/laravel.log
fi

echo ""
echo "=========================================="
echo "Test Complete"
echo "=========================================="

# Cleanup
rm -f /tmp/cookies.txt

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed.${NC}"
    exit 1
fi
