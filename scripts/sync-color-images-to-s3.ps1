param(
    [string]$SourceDisk = "public",
    [string]$TargetDisk = "s3",
    [string]$Prefix = "colors",
    [switch]$OnlyMissing,
    [switch]$DryRun
)

# This script uploads local color swatch images to the production S3 bucket.
# It relies on your local machine having the same S3 env vars that Laravel Cloud uses:
#   AWS_ACCESS_KEY_ID
#   AWS_SECRET_ACCESS_KEY
#   AWS_DEFAULT_REGION
#   AWS_BUCKET
#   AWS_ENDPOINT (if applicable)
# You can copy them from Laravel Cloud -> Resources -> Object Storage / View credentials.

$args = @()
$args += "app:sync-color-images"
$args += "--source-disk=$SourceDisk"
$args += "--target-disk=$TargetDisk"
$args += "--prefix=$Prefix"

if ($OnlyMissing) {
    $args += "--only-missing"
}

if ($DryRun) {
    $args += "--dry-run"
}

Write-Host "Running: php artisan $($args -join ' ')"
php artisan @args

