# How to setup MS Windows Task Scheduler to download a backup file

Step #1 - Enable MS Windows Powershell permissions.

1) First, Open PowerShell with Run as Administrator. (Right-click)
2) Then, run this command in PowerShell:
    Set-ExecutionPolicy -ExecutionPolicy RemoteSigned
3) After that type Y and press Enter.%

Step #2 - Save the Powershell (.ps1) [see below] script to your computer. This script contains the download URL and process instructions.

Step #3 - Configure the MS Windows Task Scheduler.

1) Open Task Scheduler
2) Under the 'Actions' pane on the right side, click on 'Create Basic Task...'
3) Give your task a name (ie. SymbtkDownload). Click Next.
4) Select 'Daily'. Click Next.
5) Modify the Start time. Click Next.
6) Select 'Start a program'. Click Next.
7) Set 'Program/script:' "C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe" AND Set 'Add arguments (optional):' <The Path to the .ps1 file downloaded in Step #2> (ie. C:\Users\username\Documents\SymbtkBackup.ps1). Click Next and Finish.

Once saved, you can test the Task Scheduler event by right clicking on the task name and selecting 'Run'. A powershell window should open when the program is launched.

--- SymbtkBackup.ps1  START
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

--- SymbtkBackup.ps1  END
