# 109 result

Files: src/Mail/ImapReader.php, src/Mail/Summarizer.php, src/Collectors/Calendar.php, src/Collectors/Mail.php, src/Cockpit/CockpitDay.php (new); CockpitApi.php (`day` block), CockpitDeps.php, public/api/cockpit.php (POST follow-up write), public/cockpit/js/views.js, data.js (`postFollow`, shared `post` helper), cockpit.css, config/collectors.php, bin/collect-facts.php. Tests: tests/CockpitDayTest.php (6), fixtures in tests/fixtures/cockpit-day/.

Green: `--filter CockpitDay` 6/6, `--filter Cockpit` 29/29, `--filter ApiGuard` 7/7.

Mutation: letting the allowlist accept `BODY[1]` without PEEK failed "the IMAP command list allows reading and nothing else". Dropping the password's `addSecret` failed "a refused sign-in says so on the panel and the app password never reaches the log or the API". Restored, green.

iCal not expanded: YEARLY, and any rule with BYMONTHDAY, BYSETPOS, BYMONTH or other parts. These show once at DTSTART and are listed under `once`.

Call: the PII hook blocked the Gmail address in config, so `gmail_user` is read from `D:\Dev\htdocs\.credentials\kc-dashboard-gmail-user`. Rob needs to create that file (or allowlist the address). Until then the panel shows "Gmail account is not set".

Not done: no live run against Gmail, Google or Ollama. No browser check. The text is part 1 decoded by how it looks, because BODYSTRUCTURE is not fetched.
