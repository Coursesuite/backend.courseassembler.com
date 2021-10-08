<?php
require_once('../vendor/autoload.php');

use \CloudConvert\CloudConvert;
use \CloudConvert\Models\Job;
use \CloudConvert\Models\Task;

$CC_API = new CloudConvert([
    'api_key' => file_get_contents("../api.key"),
    'sandbox' => false
]);

$upload_filename = realpath("file.pdf");

$job = new Job();
$job->addTask((new Task('import/upload', 'import-1')));

$job->addTask((new Task('convert', 'convert-1'))
        ->set('input_format', 'pdf')
        ->set('output_format', 'html')
        ->set('engine', 'pdf2htmlex')
        ->set('input', ['import-1'])
        ->set('outline', false)
        ->set('zoom', 1)
        ->set('embed_css', true)
        ->set('embed_javascript', true)
        ->set('embed_images', true)
        ->set('embed_fonts', true)
        ->set('split_pages', false)
        ->set('bg_format', 'jpg')
);

$job->addTask((new Task('export/url', 'output-1'))
        ->set('input', 'convert-1')
);


$CC_API->jobs()->create($job);
$uploadTask = $job->getTasks()->whereName("import-1")[0];
$CC_API->tasks()->upload($uploadTask, fopen($upload_filename, 'r'), $name);

// WAIT FOR CONVERSION
$CC_API->jobs()->wait($job);

// DOWNLOAD THE RESULTS
$converted_file_contents = "";
foreach ($job->getExportUrls() as $file) {
    $source = $CC_API->getHttpTransport()->download($file->url)->detach();
    $dest = fopen($file->filename, 'w');
    stream_copy_to_stream($source, $dest);
    echo file_get_contents($file->filename);
}
