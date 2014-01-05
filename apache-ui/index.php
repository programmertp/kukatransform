<?php
require_once("../kukatransform.class.php");

// Quick and dirty input form processor
$error_msg = "";

if (empty($_POST) === false)
{
    switch ($_FILES['input_file']['error'])
    {
        case 1:
        case 2:
            $error_msg = "The file you've chosen is too large.";
            break;
        case 3: $error_msg = "The upload failed; please try again.";
            break;
        case 4: $error_msg = "Please select a file and try again.";
            break;
    }

    if (empty($_POST['split_lines']) === true || is_numeric($_POST['split_lines']) === false)
    {
        $error_msg = "Please enter the number of lines and try again.";
    }

    if ($error_msg == "")
    {
        $KT = new KUKATransform($_FILES['input_file']['tmp_name'], $_FILES['input_file']['name'], $_POST['split_lines']);

        $zip_file = $KT->zip_filename();

        if (empty($zip_file) === true)
        {
            $error_msg = "Transform failed; please try again. <!-- {$KT->last_error} -->";
        }
        else
        {
            $zip_contents = file_get_contents($zip_file);
            $filename = $KT->basename.'.zip';
            $KT->zip_remove();

            ob_clean();

            header("Content-Disposition: attachment; filename=".urlencode($filename));
            header("Content-Type: application/force-download");
            header("Content-Type: application/octet-stream");
            header("Content-Type: application/download");
            header("Content-Description: File Transfer");
            header("Content-Length: " . strlen($zip_contents));

            print $zip_contents;
            exit();
        }
    }
}

if ($error_msg !== "")
{
    $error_msg = '<p style="color:#f00;"><b>'.$error_msg.'</p>';
}
?>

<html>
    <head>
        <title>KUKA Transformer</title>
    </head>
    <body>
        <h1>KUKA Transformer</h1>

        <?php print $error_msg ?>

        <form method="post" action="index.php" enctype="multipart/form-data">
            <input type="file" name="input_file" value="Select input SRC file" /><br />
            Number of LIN lines per file: <input type="text" name="split_lines" value="8000" size="4" /><br />
            <br />
            <input type="submit" name="submit" value="Start transform" /><br />
        </form>
    </body>
</html>