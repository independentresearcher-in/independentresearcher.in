<?php
declare(strict_types=1);

/**
 * Question Submission API
 * Handles physics question submissions from landing page
 * Sends email to bhandaryaki@gmail.com and stores in database
 */

require_once 'config.php';
require_once 'security-headers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

// CORS: restrict to same origin — this endpoint is called from index.php on the
// same domain. Wildcard CORS on an endpoint that writes to the DB is a security risk.
// If embedding from an external domain becomes a requirement, explicitly list it here.
header('Access-Control-Allow-Origin: ' . (SITE_URL ?? ''));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    // Get JSON input
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        jsonResponse(['error' => 'Invalid JSON data'], 400);
    }
    
    $email = $data['email'] ?? '';
    $question = $data['question'] ?? '';
    $withDonation = $data['with_donation'] ?? false;
    
    // Input validation
    if (empty($email) || empty($question)) {
        jsonResponse(['error' => 'Email and question are required'], 400);
    }
    
    // Validate email format
    $email = filter_var($email, FILTER_VALIDATE_EMAIL);
    if (!$email) {
        jsonResponse(['error' => 'Invalid email address'], 400);
    }
    
    // Sanitize inputs
    // Email: FILTER_VALIDATE_EMAIL already ran above — do NOT htmlspecialchars email
    // as it corrupts valid addresses containing + or & characters before DB storage.
    // PDO prepared statements protect against SQL injection regardless.
    // Question: strip_tags removes any HTML/script injection without breaking valid text.
    $question = strip_tags($question);
    
    // Length limits
    if (strlen($email) > 255) {
        jsonResponse(['error' => 'Email too long'], 400);
    }
    
    if (strlen($question) > 5000) {
        jsonResponse(['error' => 'Question too long (max 5000 characters)'], 400);
    }
    
    // Check for spam patterns
    $spamPatterns = [
        '/\b(viagra|cialis|casino|poker|lottery)\b/i',
        '/http[s]?:\/\/[^\s]+/i', // Block URLs in question
        '/\b\d{10,}\b/', // Block long number sequences
    ];
    
    foreach ($spamPatterns as $pattern) {
        if (preg_match($pattern, $question)) {
            jsonResponse(['error' => 'Invalid content detected'], 400);
        }
    }
    
    // IP-level rate limit: 10 submissions per IP per hour (fast check, no DB query)
    if (!checkRateLimit('question-submit', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 10, 3600)) {
        jsonResponse(['error' => 'Too many submissions from your network. Please try again later.'], 429);
    }

    // Email-level rate limit: max 5 submissions per hour from same email (DB-backed)
    $db = getDB();
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM physics_questions 
        WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$email]);
    $result = $stmt->fetch();
    
    if ($result['count'] >= 5) {
        jsonResponse(['error' => 'Too many submissions. Please try again later.'], 429);
    }
    
    // Store in database
    $stmt = $db->prepare("
        INSERT INTO physics_questions (email, question, with_donation, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    
    $stmt->execute([
        $email,
        $question,
        $withDonation ? 1 : 0,
        $ipAddress,
        $userAgent
    ]);
    
    $questionId = $db->lastInsertId();
    
    // Send email notification to bhandaryaki@gmail.com
    $emailSent = sendQuestionEmail($email, $question, $withDonation, $questionId);
    
    // Send confirmation email to user
    sendConfirmationEmail($email, $question);
    
    jsonResponse([
        'success' => true,
        'message' => $withDonation 
            ? 'Thank you! Your question has been submitted. Opening donation page...'
            : 'Your question has been submitted successfully! We will respond via email.',
        'question_id' => $questionId,
        'email_sent' => $emailSent
    ]);
    
} catch (PDOException $e) {
    error_log('Database error in question submission: ' . $e->getMessage());
    jsonResponse(['error' => 'Database error. Please try again later.'], 500);
} catch (Exception $e) {
    error_log('Error in question submission: ' . $e->getMessage());
    jsonResponse(['error' => 'An error occurred. Please try again.'], 500);
}

/**
 * Send email notification to researcher
 */
function sendQuestionEmail($userEmail, $question, $withDonation, $questionId) {
    $to = 'bhandaryaki@gmail.com';
    $subject = '[Physics Question #' . $questionId . '] New Question from ' . $userEmail;
    
    $message = "New physics question received:\n\n";
    $message .= "Question ID: #" . $questionId . "\n";
    $message .= "From: " . $userEmail . "\n";
    $message .= "With Donation: " . ($withDonation ? 'Yes' : 'No') . "\n";
    $message .= "Date: " . date('Y-m-d H:i:s') . "\n\n";
    $message .= "Question:\n";
    $message .= "-------------------\n";
    $message .= $question . "\n";
    $message .= "-------------------\n\n";
    $message .= "Reply to: " . $userEmail;
    
    $headers = [
        'From: Physics Questions <noreply@independentresearcher.in>',
        'Reply-To: ' . $userEmail,
        'X-Mailer: PHP/' . phpversion(),
        'Content-Type: text/plain; charset=UTF-8'
    ];
    
    return mail($to, $subject, $message, implode("\r\n", $headers));
}

/**
 * Send confirmation email to user
 */
function sendConfirmationEmail($userEmail, $question) {
    $subject = 'Your Physics Question Has Been Received';
    
    $message = "Dear Researcher,\n\n";
    $message .= "Thank you for reaching out with your physics question. We have received your inquiry:\n\n";
    $message .= "\"" . substr($question, 0, 200) . (strlen($question) > 200 ? '...' : '') . "\"\n\n";
    $message .= "We will review your question and respond via email as soon as possible.\n\n";
    $message .= "Best regards,\n";
    $message .= "Akilesh Ravindra Bhandary\n";
    $message .= "Independent Researcher\n\n";
    $message .= "---\n";
    $message .= "This is an automated confirmation email.";
    
    $headers = [
        'From: Akilesh Bhandary <noreply@independentresearcher.in>',
        'Reply-To: bhandaryaki@gmail.com',
        'X-Mailer: PHP/' . phpversion(),
        'Content-Type: text/plain; charset=UTF-8'
    ];
    
    return mail($userEmail, $subject, $message, implode("\r\n", $headers));
}
