# Function
function Invoke-FileDownload {
    <#
    .SYNOPSIS
        Given the result of WebResponseObject, download the file to disk without having to specify a name.
    .DESCRIPTION
        Given the result of WebResponseObject, download the file to disk without having to specify a name.
    .PARAMETER WebResponse
        A WebResponseObject from running an Invoke-WebRequest on a file to download
    .EXAMPLE
        # Download the Linux kernel source
        Invoke-FileDownload -Uri 'https://cdn.kernel.org/pub/linux/kernel/v6.x/linux-6.6.3.tar.xz'
        # Alias
        wget -Uri 'https://cdn.kernel.org/pub/linux/kernel/v6.x/linux-6.6.3.tar.xz'
    #>
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [String]
        $Uri,

        [Parameter(Mandatory = $false)]
        [String]
        $Directory = "$PWD"
    )

    # Manually invoke a web request
    $Request = [System.Net.WebRequest]::Create($Uri)
    $Request.AllowAutoRedirect = $true

    try {
        $Response = $Request.GetResponse()
    }
    catch {
        Write-Error 'Error: Web request failed.' -ErrorAction Stop
    }
    finally {
        $fullPath = $false

        if ($Response.StatusCode -eq 'OK') {
            $errorMessage = "Cannot determine filename for download."

            if (!($Response.Headers.Contains("Content-Disposition"))) {
                Write-Error $errorMessage -ErrorAction Stop
            }

            $content = [System.Net.Mime.ContentDisposition]::new($Response.Headers["Content-Disposition"])

            $fileName = $content.FileName

            if (!$fileName) {
                Write-Error $errorMessage -ErrorAction Stop
            }

            if (!(Test-Path -Path $Directory)) {
                New-Item -Path $Directory -ItemType Directory
            }

            $fullPath = Join-Path -Path $Directory -ChildPath $fileName
        }

        if ($fullPath) {
            $errorMessage = "File already exists. "

            if ([System.IO.File]::Exists($fullPath)) {
                Write-Error $errorMessage -ErrorAction Stop
            }
            Write-Output "Downloading to $fullPath"
            Start-BitsTransfer -Source $Uri -Destination $fullPath
            Write-Output 'Download complete.'
        }

        if ($Response) { $Response.Close() }
    }
}

Invoke-FileDownload -Uri "https://yoursite.com/portal/tk/?/backup/53/download" -Directory $HOME\Downloads\Symbportal
