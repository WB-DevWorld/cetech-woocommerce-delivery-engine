# Record all Stage 12C paced training videos sequentially (PowerShell-safe: no | in grep).
# Base: https://flairoc.com/intl/
$ErrorActionPreference = 'Continue'
$root = 'C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-woocommerce-delivery-engine'
Set-Location "$root\training\playwright-videos"

$tags = @(
  '@video01','@video02','@video03','@video04','@video05','@video06',
  '@video07','@video08','@video09','@video10','@video11','@video12'
)

$results = @()
foreach ($tag in $tags) {
  Write-Host "==== RECORDING $tag ====" -ForegroundColor Cyan
  $project = if ($tag -match 'video0[78]') { 'training-video-public' } else { 'training-video-admin' }
  npx playwright test --config=playwright.config.ts --headed --grep $tag --project=$project
  $code = $LASTEXITCODE
  $results += [pscustomobject]@{ Tag = $tag; Exit = $code }
  Write-Host "==== $tag exit=$code ====" -ForegroundColor Yellow
}

Write-Host "`n==== SUMMARY ====" -ForegroundColor Green
$results | Format-Table -AutoSize
$results | Export-Csv -NoTypeInformation "$root\training\playwright-videos\output\record-summary.csv"

# List promoted webms
Write-Host "`n==== WEBM ASSETS ====" -ForegroundColor Green
Get-ChildItem "$root\docs\training\assets\videos\*.webm" -ErrorAction SilentlyContinue |
  ForEach-Object { "{0}`t{1:N2} MB" -f $_.Name, ($_.Length/1MB) }
