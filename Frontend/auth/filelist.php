<?php
header('Content-Type: text/plain');

$repo = isset($_GET['repo']) ? $_GET['repo'] : 'marvinsehunelo/zurubank';
$branch = isset($_GET['branch']) ? $_GET['branch'] : 'main';

$treeUrl = "https://api.github.com/repos/{$repo}/git/trees/{$branch}?recursive=1";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $treeUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'zurubank -FileLister/1.0');
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

if (!isset($data['tree'])) {
    die("Error: Unable to fetch repository contents");
}

echo "zurubank  - COMPLETE FILE LIST\n";
echo "Repository: {$repo}\n";
echo "Branch: {$branch}\n";
echo str_repeat('=', 80) . "\n\n";

$totalSize = 0;
$dirCount = 0;
$fileCount = 0;

foreach ($data['tree'] as $item) {
    $path = $item['path'];
    $type = $item['type'];
    $size = isset($item['size']) ? $item['size'] : 0;
    
    if ($type === 'tree') {
        $dirCount++;
        echo "📁 {$path}/\n";
    } else {
        $fileCount++;
        $totalSize += $size;
        $sizeText = $size >= 1024 ? round($size / 1024, 2) . ' KB' : $size . ' B';
        echo "📄 {$path} ({$sizeText})\n";
    }
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "📁 Directories: {$dirCount}\n";
echo "📄 Files: {$fileCount}\n";
echo "💾 Total Size: " . ($totalSize >= 1024 ? round($totalSize / 1024, 2) . ' KB' : $totalSize . ' B') . "\n";
?>
