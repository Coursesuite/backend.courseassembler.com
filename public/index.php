<?php
// error_reporting(E_ERROR);
// ini_set("display_errors", 1);
set_time_limit(300);

header ("Access-Control-Allow-Origin: ". getenv("ORIGIN_URL"));
header ("Access-Control-Allow-Headers: *");
header ("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    die();
}

require_once('../vendor/autoload.php');

define("MAX_WIDTH", 1440);

// $headers = apache_request_headers();
// header('content-type: application/json');

use \CloudConvert\CloudConvert;
use \CloudConvert\Models\Job;
use \CloudConvert\Models\Task;

$verifier = Licence::validate(Request::post("hash"));
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
$builder = \Mimey\MimeMappingBuilder::create();
$builder->add('application/vnd.apple.keynote', 'key');
$builder->add('application/x-iwork-keynote-sffkey', 'key');
$builder->add('application/vnd.apple.pages', 'pages');
$builder->add('application/x-iwork-pages-sffpages', 'pages');
$builder->add('application/vnd.apple.numbers', 'numbers');
$builder->add('application/x-iwork-numbers-sffnumbers', 'numbers');

$Mimey = new \Mimey\MimeTypes;
$mimeext = $Mimey->getExtension($mime);

// AN EXTENSION IS REQUIRED
if (empty($mimeext)) {
    $mimeext = pathinfo($name, PATHINFO_EXTENSION);
}

if (empty($mimeext)) {
    Utils::Stop(500, '{"error":"The file was not understood or was not valid"}');
}

// CREATE A WORKING DIR
$jobsroot = realpath('../jobs');
$workingdir =  "{$jobsroot}/{$verifier->hash}/{$fileid}/";
if (!file_exists($workingdir)) mkdir ($workingdir, 0777, true);
if (!file_exists($workingdir)) Utils::Stop(404, '{"error":"Permissions are preventing conversion from taking place"}');

WriteToLog("Job started " . time());
WriteToLog($_POST);
WriteToLog($upload);

// DETERMINE CONVERSION TYPE
$conversionTarget = $website ? "website" : "html";
$targetFormats = [
    "pdf" => ["odd","epub","mobi","lit","pages","numbers","ods","cdr","eps", "odt","pptx","ppt","key","numbers","pages","doc","docx","xls","xlsx"],
    "jpg" => ["psd","tiff","webp","ps","wps","azw","bmp","nef","raw","xps"],
    "png" => ["svg","ai"],
];

if (!empty($mimeext)) {
    foreach($targetFormats as $key => $value) {
        if (in_array($mimeext, $value)) {
            $conversionTarget = $key;
        }
    }
}

WriteToLog("extension from mime=". $mimeext);
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
                CreateImportTask("{$fileid}-import", $url)
            )
            ->addTask(
                ConvertToHtml("{$fileid}-import", $job_result ,$mimeext)
            );
    break;

    case "pdf":
        $job
            ->addTask(
                CreateImportTask("{$fileid}-import", $url)
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
                CreateImportTask("{$fileid}-import", $url)
            )
            ->addTask(
                ConvertToJpg("{$fileid}-import", $job_result, $mimeext)
            );
    break;

    case "png":
        $job_result = "{$fileid}-png";
        $job
            ->addTask(
                CreateImportTask("{$fileid}-import", $url)
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
if ($conversionTarget === "website" || ($upload === false && !empty($url))) {
    WriteToLog("Nothing to upload, url=" . $url);
    // nothing to do
} else {
    $uploadTask = $job->getTasks()->whereName("{$fileid}-import")[0];
    WriteToLog("Uploading " . $upload_filename);
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

if (empty($converted_file_contents)) {
    Utils::Stop(500, '{"error":"An error occurred converting this file (unsupported)."}');
}

// CREATE THE FILEINFO PAYLOAD
$result = new stdClass();
$result->payload = new stdClass();
if ($website || in_array($conversionTarget, ["jpg","png"])) {
    $result->name = $name;
    $result->format = $conversionTarget;
    $result->kind = 'image';
    $result->source = $url;
    $result->payload->image = "data:image/" . ($conversionTarget==="jpg" ? "jpeg" : "png") . ";base64," . base64_encode($converted_file_contents);
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
WriteToLog($json);
Utils::stop(200, $json, false, 'text/plain', $workingdir);


// UTILITIES
function WriteToLog($contents) {
    global $workingdir;
    if (gettype($contents) !== "string") $contents = var_export($contents, true);
    file_put_contents($workingdir . '/log.txt', $contents . PHP_EOL, FILE_APPEND);
}


// CLOUD CONVERT TASKS
function CreateImportTask($output, $url = '') {
    if (empty($url)) {
        $TASK = (new Task('import/upload', $output));
        WriteToLog("Task is Upload");
    } else {
        $TASK = (new Task('import/url', $output))
            ->set('url', $url)
            ->set('filename','output.pdf');
        WriteToLog("Task is URL");
    }
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
        ->set('timeout', 300)
        ->set('wait_until', 'networkidle2')
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