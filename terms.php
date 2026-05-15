<?php
/**
 * terms.php — Standalone Terms and Conditions page
 * URL: https://independentresearcher.in/terms
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Terms &amp; Conditions — <?php echo esc(SITE_NAME); ?></title>
  <meta name="description" content="Terms and conditions for independentresearcher.in — the interactive physics simulation platform by Akilesh Ravindra Bhandary.">
  <link rel="canonical" href="<?php echo esc(SITE_URL); ?>/terms">
  <meta property="og:title"       content="Terms &amp; Conditions — <?php echo esc(SITE_NAME); ?>">
  <meta property="og:description" content="Terms and conditions for the use of independentresearcher.in.">
  <meta property="og:url"         content="<?php echo esc(SITE_URL); ?>/terms">
  <meta name="robots" content="index, follow">
  <link rel="stylesheet" href="assets/css/theme.css">
  <script>
  (function(){
    var t = localStorage.getItem('ir_theme');
    if (!t) { var h = new Date().getHours(); t = (h>=6&&h<18)?'light':'dark'; }
    document.documentElement.setAttribute('data-theme', t);
    document.documentElement.classList.add('theme-' + t);
  })();
  </script>
  <style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  html { scroll-behavior: smooth; }
  body { font-family: 'Segoe UI', system-ui, sans-serif; line-height: 1.75; font-size: 15px; }

  /* Nav */
  .t-nav {
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 2rem;
    border-bottom: 1px solid var(--border-color, #2d3748);
    position: sticky;
    top: 0;
    z-index: 50;
    background: var(--bg-secondary, #0d1117);
    backdrop-filter: blur(12px);
  }
  .t-nav a { text-decoration: none; color: var(--text-secondary, #94a3b8); font-size: 0.875rem; }
  .t-nav .brand { font-weight: 700; font-size: 1rem; color: var(--text-primary, #e2e8f0); }
  .t-nav .brand span { color: var(--color-accent, #f0c040); }
  .t-nav a:hover { color: var(--color-accent, #f0c040); }

  /* Page */
  .t-wrap { max-width: 740px; margin: 0 auto; padding: 3.5rem 1.5rem 6rem; }
  .t-tag {
    font-size: 0.65rem;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--color-accent, #f0c040);
    margin-bottom: 0.6rem;
    font-family: 'Courier New', monospace;
  }
  h1 { font-size: 2.2rem; font-weight: 800; color: var(--text-primary, #e2e8f0); margin-bottom: 0.4rem; line-height: 1.2; }
  .t-date { font-size: 0.78rem; color: var(--text-secondary, #94a3b8); font-family: 'Courier New', monospace; margin-bottom: 2.5rem; }

  /* TOC */
  .toc {
    background: var(--bg-tertiary, #161b22);
    border: 1px solid var(--border-color, #2d3748);
    border-radius: 10px;
    padding: 1.4rem 1.5rem;
    margin-bottom: 3rem;
  }
  .toc h4 { font-size: 0.62rem; letter-spacing: 2px; text-transform: uppercase; color: var(--text-secondary, #94a3b8); margin-bottom: 0.8rem; }
  .toc ol { list-style: none; display: flex; flex-direction: column; gap: 0.4rem; }
  .toc ol li a {
    color: var(--text-secondary, #94a3b8);
    text-decoration: none;
    font-size: 0.84rem;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    transition: color 0.15s;
  }
  .toc ol li a:hover { color: var(--color-accent, #f0c040); }
  .toc ol li a .n { font-family: 'Courier New', monospace; font-size: 0.68rem; color: var(--color-accent, #f0c040); min-width: 1.5rem; }

  /* Sections */
  .tc-s { margin-bottom: 2.5rem; scroll-margin-top: 76px; }
  .tc-s h2 {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--text-primary, #e2e8f0);
    margin-bottom: 1rem;
    padding-bottom: 0.6rem;
    border-bottom: 1px solid var(--border-color, #2d3748);
    display: flex;
    align-items: center;
    gap: 0.7rem;
  }
  .tc-s h2 .sn {
    font-family: 'Courier New', monospace;
    font-size: 0.62rem;
    color: var(--color-accent, #f0c040);
    background: rgba(240,192,64,0.12);
    padding: 2px 7px;
    border-radius: 4px;
  }
  .tc-s p { color: var(--text-secondary, #94a3b8); margin-bottom: 0.8rem; font-size: 0.9rem; }
  .tc-s p:last-child { margin-bottom: 0; }
  .tc-s strong { color: var(--text-primary, #e2e8f0); font-weight: 600; }
  .tc-s ul { list-style: none; margin: 0.4rem 0 0.8rem; display: flex; flex-direction: column; gap: 0.35rem; }
  .tc-s ul li { color: var(--text-secondary, #94a3b8); font-size: 0.9rem; padding-left: 1rem; position: relative; }
  .tc-s ul li::before { content: ''; position: absolute; left: 0; top: 0.65em; width: 4px; height: 4px; border-radius: 50%; background: var(--color-accent, #f0c040); opacity: 0.7; }
  .tc-s a { color: var(--color-accent, #f0c040); }

  /* Highlight box */
  .hbox {
    background: rgba(240,192,64,0.07);
    border: 1px solid rgba(240,192,64,0.22);
    border-radius: 8px;
    padding: 0.9rem 1.1rem;
    margin: 0.8rem 0;
    font-size: 0.87rem;
    color: var(--text-secondary, #94a3b8);
  }
  .hbox strong { color: var(--color-accent, #f0c040); display: block; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.3rem; }

  /* Footer */
  .t-footer {
    background: var(--bg-tertiary, #161b22);
    border-top: 1px solid var(--border-color, #2d3748);
    padding: 2rem;
    text-align: center;
    margin-top: 4rem;
  }
  .t-footer .fb { font-weight: 700; color: var(--text-primary, #e2e8f0); margin-bottom: 0.3rem; }
  .t-footer .fb span { color: var(--color-accent, #f0c040); }
  .t-footer p { color: var(--text-secondary, #94a3b8); font-size: 0.8rem; margin-bottom: 0.8rem; }
  .t-footer .fl { display: flex; justify-content: center; gap: 1.5rem; flex-wrap: wrap; }
  .t-footer .fl a { color: var(--text-secondary, #94a3b8); text-decoration: none; font-size: 0.82rem; }
  .t-footer .fl a:hover { color: var(--color-accent, #f0c040); }

  @media (max-width: 640px) {
    h1 { font-size: 1.6rem; }
    .t-wrap { padding: 2rem 1rem 4rem; }
    .t-nav { padding: 0 1rem; }
  }
  </style>
</head>
<body>

<nav class="t-nav">
  <a href="/" class="brand">Akilesh <span>Bhandary</span></a>
  <a href="/">Back to Site</a>
</nav>

<div class="t-wrap">
  <div class="t-tag">Legal Document</div>
  <h1>Terms &amp; Conditions</h1>
  <p class="t-date">Effective Date: May 2026 &nbsp;|&nbsp; independentresearcher.in</p>

  <div class="toc">
    <h4>Contents</h4>
    <ol>
      <li><a href="#s1"><span class="n">01</span> Acceptance of Terms</a></li>
      <li><a href="#s2"><span class="n">02</span> About This Website</a></li>
      <li><a href="#s3"><span class="n">03</span> Use of the Platform</a></li>
      <li><a href="#s4"><span class="n">04</span> Accounts and Authentication</a></li>
      <li><a href="#s5"><span class="n">05</span> Simulations and Content Access</a></li>
      <li><a href="#s6"><span class="n">06</span> Payments and Donations</a></li>
      <li><a href="#s7"><span class="n">07</span> Intellectual Property</a></li>
      <li><a href="#s8"><span class="n">08</span> Cookies and Data</a></li>
      <li><a href="#s9"><span class="n">09</span> Third-Party Services</a></li>
      <li><a href="#s10"><span class="n">10</span> Disclaimers and Limitation of Liability</a></li>
      <li><a href="#s11"><span class="n">11</span> Content Submitted by Users</a></li>
      <li><a href="#s12"><span class="n">12</span> Privacy and Data Handling</a></li>
      <li><a href="#s13"><span class="n">13</span> Termination</a></li>
      <li><a href="#s14"><span class="n">14</span> Governing Law</a></li>
      <li><a href="#s15"><span class="n">15</span> Changes to These Terms</a></li>
      <li><a href="#s16"><span class="n">16</span> Contact</a></li>
    </ol>
  </div>

  <div class="tc-s" id="s1">
    <h2><span class="sn">01</span> Acceptance of Terms</h2>
    <p>By accessing or using <strong>independentresearcher.in</strong> (the "Website"), you confirm that you have read, understood, and agree to be bound by these Terms and Conditions ("Terms"). If you do not agree to any part of these Terms, you must not use this Website.</p>
    <p>These Terms apply to all visitors, registered account holders, simulation users, question submitters, and donors.</p>
  </div>

  <div class="tc-s" id="s2">
    <h2><span class="sn">02</span> About This Website</h2>
    <p>This Website is operated by <strong>Akilesh Ravindra Bhandary</strong>, an independent researcher based in India. The Website provides:</p>
    <ul>
      <li>Interactive physics and mathematics simulations for educational use</li>
      <li>A platform to submit physics and mathematics questions to the researcher</li>
      <li>Research blog posts covering physics, mathematics, and scientific topics</li>
      <li>A mechanism to support the research through voluntary donations</li>
    </ul>
    <p>This is a personal research and education platform, not affiliated with any university, institution, or commercial entity unless explicitly stated.</p>
  </div>

  <div class="tc-s" id="s3">
    <h2><span class="sn">03</span> Use of the Platform</h2>
    <p>You agree to use this Website only for lawful purposes and in a manner that does not infringe the rights of others. You must not:</p>
    <ul>
      <li>Attempt to gain unauthorised access to any part of the Website, its servers, or associated databases</li>
      <li>Use automated tools, bots, or scrapers to harvest content or overload server resources</li>
      <li>Attempt to reverse-engineer, decompile, or extract source code from the simulation files</li>
      <li>Submit false, misleading, or harmful content through any form on the Website</li>
      <li>Use the Website to distribute malware, spam, or any harmful content</li>
      <li>Misrepresent your identity or affiliation when submitting questions or making contact</li>
    </ul>
  </div>

  <div class="tc-s" id="s4">
    <h2><span class="sn">04</span> Accounts and Authentication</h2>
    <p>Some features require a registered account. You may register using an email address and password, or by signing in with a Google account via OAuth.</p>
    <p><strong>Account responsibility:</strong> You are responsible for maintaining the confidentiality of your credentials. Report suspected unauthorised access to <strong>bhandaryaki@gmail.com</strong> immediately.</p>
    <p><strong>Minimum age:</strong> This platform is intended for users aged 13 and above.</p>
  </div>

  <div class="tc-s" id="s5">
    <h2><span class="sn">05</span> Simulations and Content Access</h2>
    <p>All simulations are currently provided free of charge for personal learning, academic study, teaching demonstrations, and non-commercial research purposes.</p>
    <div class="hbox">
      <strong>Access Model</strong>
      All simulations are currently free. A paid tier for extended features is planned. Terms applicable to paid access will be published at launch.
    </div>
    <p>You may not copy, reproduce, redistribute, embed, resell, or sublicense simulation content without prior written consent. Simulations are illustrative models and must not be relied upon as a substitute for professional scientific analysis.</p>
  </div>

  <div class="tc-s" id="s6">
    <h2><span class="sn">06</span> Payments and Donations</h2>
    <p>Donations may be made through Razorpay, subject to Razorpay's Terms of Service. All donations are voluntary and do not entitle the donor to any specific service or research output.</p>
    <p><strong>Refunds:</strong> Donations are generally non-refundable. Contact <strong>bhandaryaki@gmail.com</strong> within 7 days of a transaction if you believe a payment was processed in error. Refund requests are reviewed case-by-case.</p>
    <p><strong>Donor visibility:</strong> Donors who consent to public listing will have their name displayed in the "Recent Donors" section. Select the anonymous option to withhold your name, or request removal by email.</p>
    <p>All transactions are processed in Indian Rupees (INR). You are responsible for applicable taxes in your jurisdiction.</p>
  </div>

  <div class="tc-s" id="s7">
    <h2><span class="sn">07</span> Intellectual Property</h2>
    <p>All content on this Website — including simulation source code, written text, blog posts, graphics, and the site design — is the intellectual property of <strong>Akilesh Ravindra Bhandary</strong> protected under applicable copyright law.</p>
    <p>You are granted a limited, non-exclusive, non-transferable licence to access content for your personal, non-commercial educational use. Brief quotation for commentary, criticism, or educational citation is permitted provided clear attribution is given to <strong>independentresearcher.in</strong>.</p>
  </div>

  <div class="tc-s" id="s8">
    <h2><span class="sn">08</span> Cookies and Data</h2>
    <p><strong>Strictly Necessary:</strong> A PHP session cookie (<code>PHPSESSID</code>) maintains your login session. It contains only a session identifier, stores no personal data, and expires when you close your browser or log out.</p>
    <p><strong>Optional analytics cookies (loaded only with your consent):</strong></p>
    <ul>
      <li><strong>Google Analytics 4</strong> (Google LLC) &mdash; collects anonymised usage statistics such as page views and session duration to help us understand how visitors use the site.</li>
      <li><strong>Microsoft Clarity</strong> (Microsoft Corporation) &mdash; records anonymised session replays and generates heatmaps to identify usability issues. Clarity may capture click, scroll, and movement data. No personally identifiable information is intentionally collected. See <a href="https://privacy.microsoft.com/en-us/privacystatement" rel="noopener noreferrer" target="_blank">Microsoft Privacy Statement</a>.</li>
      <li><strong>Razorpay</strong> (Razorpay Software Pvt. Ltd.) &mdash; sets analytics cookies when you initiate a payment to assist with fraud detection and payment reliability.</li>
    </ul>
    <p>You may decline all optional cookies via the banner shown on first visit. Declining has no effect on any site feature other than disabling the above analytics.</p>
    <p><strong>localStorage:</strong> Your theme preference and cookie consent choice are stored locally in your browser and never transmitted to any server.</p>
  </div>

  <div class="tc-s" id="s9">
    <h2><span class="sn">09</span> Third-Party Services</h2>
    <p>This Website integrates the following third-party services, each governed by their own terms and privacy policies:</p>
    <ul>
      <li><strong>Google Analytics 4</strong> — usage analytics (Google LLC)</li>
      <li><strong>Microsoft Clarity</strong> — heatmaps and session recordings (Microsoft Corporation)</li>
      <li><strong>Google Sign-In (OAuth 2.0)</strong> — account authentication (Google LLC)</li>
      <li><strong>Razorpay</strong> — payment processing (Razorpay Software Pvt. Ltd.)</li>
    </ul>
  </div>

  <div class="tc-s" id="s10">
    <h2><span class="sn">10</span> Disclaimers and Limitation of Liability</h2>
    <p>This Website and its content are provided on an <strong>"as is" and "as available"</strong> basis without warranties of any kind. To the maximum extent permitted by applicable law, Akilesh Ravindra Bhandary shall not be liable for any direct, indirect, incidental, or consequential damages arising from your use of this Website, errors in simulation content, or service interruptions.</p>
    <p>Nothing in these Terms excludes liability for death or personal injury caused by negligence, or any other liability that cannot be excluded under applicable Indian law.</p>
  </div>

  <div class="tc-s" id="s11">
    <h2><span class="sn">11</span> Content Submitted by Users</h2>
    <p>When you submit a question or message, you grant Akilesh Ravindra Bhandary a non-exclusive, royalty-free licence to use, store, and respond to that content for the purposes of answering your query and improving the platform.</p>
    <p>Questions may, at the researcher's discretion, be paraphrased and published as blog content without identifying the submitter, unless you have requested confidentiality.</p>
  </div>

  <div class="tc-s" id="s12">
    <h2><span class="sn">12</span> Privacy and Data Handling</h2>
    <p>When you register or submit a question, we collect and store your email address and any information you voluntarily provide. This data is used solely to provide the services described on this Website and is never sold, rented, or shared with third parties except as required to operate the services in Section 9 or as required by law.</p>
    <div class="hbox">
      <strong>Data Retention</strong>
      Account data is retained while the account is active. Donation records are retained for a minimum of 5 years for financial compliance. You may request deletion of your account and non-financial data at any time.
    </div>
    <p>To exercise your right of access, correction, or deletion, contact <strong>bhandaryaki@gmail.com</strong>. We will respond within 30 days.</p>
  </div>

  <div class="tc-s" id="s13">
    <h2><span class="sn">13</span> Termination</h2>
    <p>The researcher reserves the right to suspend or terminate any user's access without prior notice if they violate these Terms. You may close your account at any time by emailing <strong>bhandaryaki@gmail.com</strong>.</p>
  </div>

  <div class="tc-s" id="s14">
    <h2><span class="sn">14</span> Governing Law</h2>
    <p>These Terms are governed by the laws of <strong>India</strong>. Any disputes shall be subject to the exclusive jurisdiction of the courts in India. If any provision is found unenforceable, it shall be modified to the minimum extent necessary and all other provisions remain in effect.</p>
  </div>

  <div class="tc-s" id="s15">
    <h2><span class="sn">15</span> Changes to These Terms</h2>
    <p>These Terms may be updated to reflect changes in the Website's features, legal requirements, or operational practices. The Effective Date above will be updated when changes are made. Continued use after the effective date constitutes acceptance of the revised Terms.</p>
    <p>For significant changes, registered users will be notified by email at least 7 days before the changes take effect.</p>
  </div>

  <div class="tc-s" id="s16">
    <h2><span class="sn">16</span> Contact</h2>
    <div class="hbox">
      <strong>Contact Information</strong>
      <strong style="color:var(--text-primary,#e2e8f0);font-size:0.9rem;">Akilesh Ravindra Bhandary</strong><br>
      Email: <a href="mailto:bhandaryaki@gmail.com">bhandaryaki@gmail.com</a><br>
      Phone: <a href="tel:+919819323326">+91 98193 23326</a><br>
      Website: <a href="<?php echo esc(SITE_URL); ?>"><?php echo esc(SITE_URL); ?></a>
    </div>
    <p>We aim to respond to all enquiries within 5 business days.</p>
  </div>

</div>

<footer class="t-footer">
  <div class="fb">Akilesh <span>Bhandary</span></div>
  <p>Independent researcher in physics and mathematics.</p>
  <div class="fl">
    <a href="/">Home</a>
    <a href="/#simulations">Simulations</a>
    <a href="/#about">About</a>
    <a href="mailto:bhandaryaki@gmail.com">Contact</a>
  </div>
  <p style="margin-top:1rem;">&copy; <?php echo date('Y'); ?> Akilesh Ravindra Bhandary. All rights reserved.</p>
</footer>

</body>
</html>
