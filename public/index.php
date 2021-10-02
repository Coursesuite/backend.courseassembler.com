<?php
// error_reporting(E_ERROR);
// ini_set("display_errors", 1);
set_time_limit(300);

header ("Access-Control-Allow-Origin: *");
header ("Access-Control-Allow-Headers: *");
header ("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    die();
}

require_once('../vendor/autoload.php');

define("MAX_WIDTH", 1440);

// $headers = apache_request_headers();
// header('content-type: application/json');

use \CloudConvert\CloudConvert;
use \CloudConvert\Models\Job;
use \CloudConvert\Models\Task;

$verifier = Licence::validate(Request::get("hash"));
if (!$verifier->valid) Utils::stop(403, '{"error":"forbidden"}');

$fileid     = Request::post("fileid");              // file-kf783op-0
$name       = Request::post("name");                  // mybook.epub
$mime       = Request::post("type");                  // application/epub+zip
$url        = Request::post("url");
$website    = (Request::post("website") === "true");
$src        = Request::post("src");
$upload     = Request::file("blob");
$upload_filename = $upload ? $upload["tmp_name"] : "";

// https://packagist.org/packages/ralouphie/mimey
$Mimey = new \Mimey\MimeTypes;
$mimeext = $Mimey->getExtension($mime);

// CREATE A WORKING DIR
$jobsroot = realpath('../jobs');
$workingdir =  "{$jobsroot}/{$verifier->hash}/{$fileid}/";
if (!file_exists($workingdir)) mkdir ($workingdir, 0777, true);
if (!file_exists($workingdir)) Utils::Stop(404, '{"error":"permissions"}');

WriteToLog("Job started " . time());
WriteToLog($_POST);
WriteToLog($upload);

// DETERMINE CONVERSION TYPE
$conversionTarget = $website ? "website" : "html";
$targetFormats = [
    "pdf" => ["odd","epub","mobi","lit","pages","numbers","ods","cdr","eps"], // and maybe odt
    "jpg" => ["psd","tiff","webp","ps","wps","azw","bmp","nef","raw","xps"],
    "png" => ["svg"],
];

if (!empty($mimeext)) {
    foreach($targetFormats as $key => $value) {
        if (in_array($mimeext, $value)) {
            $conversionTarget = $key;
        }
    }
}

WriteToLog("conversionTarget=". $conversionTarget);

// SET UP API
$CC_API = new CloudConvert([
    'api_key' => file_get_contents("../api.key"),
    'sandbox' => false
]);


// SET UP CONVERSION JOB
$job = new Job();
$job_result = "{$fileid}-html";

switch ($conversionTarget) {
    case "html":
        $job
            ->addTask(
                CreateImportTask("{$fileid}-import")
            )
            ->addTask(
                ConvertToHtml("{$fileid}-import", $job_result ,$mimeext)
            );
    break;

    case "pdf":
        $job
            ->addTask(
                CreateImportTask("{$fileid}-import")
            )
            ->addTask(
                ConvertToPdf("{$fileid}-import", "{$fileid}-pdf", $mimeext)
            )
            ->addTask(
                ConvertPdfToHtml("{$fileid}-pdf", $job_result)
            );
    break;

    case "jpg":
        $job_result = "{$fileid}-jpg";
        $job
            ->addTask(
                CreateImportTask("{$fileid}-import")
            )
            ->addTask(
                ConvertToJpg("{$fileid}-import", $job_result, $mimeext)
            );
    break;

    case "png":
        $job_result = "{$fileid}-png";
        $job
            ->addTask(
                CreateImportTask("{$fileid}-import")
            )
            ->addTask(
                ConvertToPng("{$fileid}-import", $job_result, $mimeext)
            );
    break;

    case "website":
        $job_result = "{$fileid}-jpg";
        $job
            ->addTask(
                CaptureWebsite($url, $job_result)
            );
    break;

}


// EXPORT THE CONVERSION RESULT
$job->addTask(CreateExportTask($job_result, $fileid));


// UPLOAD THE JOB
$CC_API->jobs()->create($job);
if ($conversionTarget !== "website") {
    $uploadTask = $job->getTasks()->whereName("{$fileid}-import")[0];
    $CC_API->tasks()->upload($uploadTask, fopen($upload_filename, 'r'), $name);
}


// WAIT FOR CONVERSION
$CC_API->jobs()->wait($job);


// DOWNLOAD THE RESULTS
$converted_file_contents = "";
foreach ($job->getExportUrls() as $file) {
    WriteToLog($file);
    $source = $CC_API->getHttpTransport()->download($file->url)->detach();
    $dest = fopen($workingdir . '/' . $file->filename, 'w');
    stream_copy_to_stream($source, $dest);
    $converted_file_contents = file_get_contents($workingdir . '/' . $file->filename);
}


// CREATE THE FILEINFO PAYLOAD
$result = new stdClass();
$result->payload = new stdClass();
if ($website) {
    $result->name = $name;
    $result->format = 'jpg';
    $result->kind = 'image';
    $result->source = $url;
    $result->payload->image = "data:image/jpeg;base64," . base64_encode($converted_file_contents);
    $result->payload->name = $name;
    $result->payload->backgroundColor = '#ffffff';
} else {
    $result->name = pathinfo($name, PATHINFO_FILENAME);
    $result->payload->html = $converted_file_contents;
    $result->format = $conversionTarget;
    $result->kind = 'file';
    $result->type = $mime;
    $result->src = $src;
}


// WRITE OUTPUT AND STOP
$json = json_encode($result, JSON_NUMERIC_CHECK | JSON_PARTIAL_OUTPUT_ON_ERROR);
Utils::stop(200, $json, false, 'text/plain', $workingdir);


// UTILITIES
function WriteToLog($contents) {
    global $workingdir;
    if (gettype($contents) !== "string") $contents = var_export($contents, true);
    file_put_contents($workingdir . '/log.txt', $contents . PHP_EOL, FILE_APPEND);
}


// CLOUD CONVERT TASKS
function CreateImportTask($output) {
    $TASK = (new Task('import/upload', $output));
    return $TASK;
}

function ConvertToZip($input, $output) {
    $TASK = (new Task('archive', $output))
        ->set('output_format', 'zip')
        ->set('engine', 'archivetool')
        ->set('input', [$input])
        ->set('filename', 'output.zip');
    return $TASK;
}

function ConvertPdfToHtml($input, $output) {
    $TASK = (new Task('convert', $output))
        ->set('input_format', 'pdf')
        ->set('output_format', 'html')
        ->set('engine', 'pdf2htmlex')
        ->set('input', [$input])
        ->set('outline', false)
        ->set('zoom', 1)
        ->set('embed_css', true)
        ->set('embed_javascript', true)
        ->set('embed_images', true)
        ->set('embed_fonts', true)
        ->set('split_pages', false)
        ->set('bg_format', 'jpg');
    return $TASK;
}

function CaptureWebsite($url, $output) {
    $TASK = (new Task('capture-website', $output))
        ->set('url', $url)
        ->set('output_format', 'jpg')
        ->set('engine', 'chrome')
        ->set('screen_width', MAX_WIDTH)
        ->set('fit', 'max')
        ->set('quality', 90)
        ->set('wait_until', 'load')
        ->set('wait_time', 0)
        ->set('filename', 'output.jpg');
    return $TASK;
}

function ConvertToPdf($input, $output, $format) {
    $TASK = (new Task('convert', $output))
        ->set('input_format', $format)
        ->set('output_format', 'pdf')
        ->set('input', [$input])
        ->set('pdf_a', false)
        ->set('filename', 'output.pdf');
    return $TASK;
}

function ConvertToHtml($input, $output, $format) {
    $TASK = (new Task('convert', $output))
        ->set('input_format', $format)
        ->set('output_format', 'html')
        ->set('input', [$input])
        ->set('embed_images', true)
        ->set('filename', 'output.html');
    return $TASK;
}

function ConvertToJpg($input, $output, $format) {
    $TASK = (new Task('convert', $output))
        ->set('input_format', $format)
        ->set('output_format', 'jpg')
        ->set('input', [$input])
        ->set('fit', 'max')
        ->set('strip', true)
        ->set('quality', 90)
        ->set('width', MAX_WIDTH)
        ->set('filename', 'output.jpg');
    return $TASK;
}

function ConvertToPng($input, $output, $format) {
    $TASK = (new Task('convert', $output))
        ->set('input_format', $format)
        ->set('output_format', 'png')
        ->set('input', [$input])
        ->set('pixel_density', 96)
        ->set('filename', 'output.png');
    return $TASK;
}

function CreateExportTask($input, $fileid) {
    $TASK = (new Task('export/url', $fileid . '-export'))
        ->set('input', $input);
    return $TASK;
}