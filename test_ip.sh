#!/bin/bash

# Test script for IP filter in index.php

echo "Testing IP filter..."

# Test with allowed IP (127.0.0.1)
echo "Testing with allowed IP 127.0.0.1:"
curl -s -o /dev/null -w "HTTP Code: %{http_code}\n" http://localhost:9000/api/v1/labels

# Test with forbidden IP (192.168.1.1)
echo "Testing with forbidden IP 192.168.1.1:"
curl -s -o /dev/null -w "HTTP Code: %{http_code}\n" -H "X-Forwarded-For: 192.168.1.1" http://localhost:9000/api/v1/labels

echo "Test completed."