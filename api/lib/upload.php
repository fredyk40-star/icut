<?php
/**
 * Vercel Blob upload handler
 * Replaces local file uploads with Vercel Blob storage
 */

function uploadFile($file, $folder = 'uploads') {
    // Check if Vercel Blob is configured
    $blobToken = env('BLOB_READ_WRITE_TOKEN');
    
    if (!$blobToken) {
        // Fallback: return a data URL or placeholder
        return null;
    }
    
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    
    $fileName = uniqid() . '_' . basename($file['name']);
    $filePath = $file['tmp_name'];
    $fileType = mime_content_type($filePath);
    
    // Use Vercel Blob SDK or REST API
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.vercel.com/v2/blobs/upload",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CurlFile($filePath, $fileType, $fileName),
            'pathname' => "$folder/$fileName"
        ],
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $blobToken"
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 201 || $httpCode === 200) {
        $data = json_decode($response, true);
        return $data['url'] ?? null;
    }
    
    error_log("Vercel Blob upload failed: HTTP $httpCode - $response");
    return null;
}

function deleteFile($url) {
    $blobToken = env('BLOB_READ_WRITE_TOKEN');
    if (!$blobToken || !$url) {
        return false;
    }
    
    // Extract blob path from URL
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.vercel.com/v2/blobs/delete",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['url' => $url]),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $blobToken",
            "Content-Type: application/json"
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200 || $httpCode === 204;
}
