<?php
/**
 * This class represents a RAR archive, which may be formed by several volumes (parts) and which contains a number of
 * RAR entries (i.e., files, directories and other special objects such as symbolic links).
 *
 * Objects of this class can be traversed, yielding the entries stored in the respective RAR archive.
 * Those entries can also be obtained through {@see \RarArchive::getEntry} and {@see \RarArchive::getEntries}.
 *
 * @link https://php.net/manual/ru/class.rararchive.php
 */
final class RarArchive /* implements Traversable */
{
    /**
     * Open RAR archive
     *
     * @param string $filename Path to the Rar archive
     * @param string $password A plain password, if needed to decrypt the headers. It will also be used by default if
     *      encrypted files are found. Note that the files may have different passwords in respect
     *      to the headers and among them
     * @param callable $volume_callback A function that receives one parameter – the path of the volume that was
     *      not found – and returns a string with the correct path for such volume or NULL if such volume does not
     *      exist or is not known. The programmer should ensure the passed function doesn't cause loops as this
     *      function is called repeatedly if the path returned in a previous call did not correspond to the needed
     *      volume. Specifying this parameter omits the notice that would otherwise be emitted whenever a volume is
     *      not found; an implementation that only returns NULL can therefore be used to merely omit such notices
     *
     * @link https://php.net/manual/en/rararchive.open.php
     *
     * @return RarArchive the requested RarArchive instance or FALSE on failure.
     */
    public static function open($filename, $password = null, callable $volume_callback = null)
    {
    }
    /**
     * Close RAR archive and free all resources
     *
     * @link https://php.net/manual/en/rararchive.close.php
     *
     * @return bool TRUE on success or FALSE on failure
     */
    public function close()
    {
    }
    /**
     * Get comment text from the RAR archive
     *
     * @link https://php.net/manual/en/rararchive.getcomment.php
     *
     * @return string the comment or NULL if there is none
     */
    public function getComment()
    {
    }
    /**
     * Get full list of entries from the RAR archive
     *
     * @return RarEntry[] array of {@see RarEntry} objects or FALSE on failure
     */
    public function getEntries()
    {
    }
    /**
     * Get entry object from the RAR archive
     *
     * Get entry object (file or directory) from the RAR archive
     *
     * @link https://php.net/manual/en/rararchive.getentry.php
     *
     * @param string $entryname Path to the entry within the RAR archive
     *
     * @return RarEntry the matching RarEntry object or FALSE on failure
     */
    public function getEntry($entryname)
    {
    }
    /**
     * Test whether an archive is broken (incomplete)
     *
     * This function determines whether an archive is incomplete, i.e., if a volume is missing or a volume is truncated.
     *
     * @link https://php.net/manual/en/rararchive.isbroken.php
     *
     * @return bool Returns TRUE if the archive is broken, FALSE otherwise. This function may also return FALSE if
     *         the passed file has already been closed. The only way to tell the two cases apart is to enable
     *         exceptions with {@see RarException::setUsingExceptions()}; however, this should be unnecessary as a program
     *         should not operate on closed files.
     */
    public function isBroken()
    {
    }
    /**
     * Check whether the RAR archive is solid
     *
     * Check whether the RAR archive is solid. Individual file extraction is slower on solid archives
     *
     * @link https://php.net/manual/enrararchive.issolid.php
     *
     * @return bool TRUE if the archive is solid, FALSE otherwise
     */
    public function  isSolid()
    {
    }
    /**
     * Whether opening broken archives is allowed
     *
     * This method defines whether broken archives can be read or all the operations that attempt to extract the
     * archive entries will fail. Broken archives are archives for which no error is detected when the file is
     * opened but an error occurs when reading the entries.
     *
     * @link https://php.net/manual/ru/rararchive.setallowbroken.php
     *
     * @param bool $allow_broken Whether to allow reading broken files (TRUE) or not (FALSE)
     *
     * @return bool TRUE или FALSE в случае возникновения ошибки. It will only fail if the file has already been closed
     */
    public function setAllowBroken($allow_broken)
    {
    }
    /**
     * Get text representation
     *
     * Provides a string representation for this RarArchive object. It currently shows the full path name of the
     * archive volume that was opened and whether the resource is valid or was already closed through a
     * call to {@see RarArchive::close()}.
     *
     * This method may be used only for debugging purposes, as there are no guarantees as to which information the
     * result contains or how it is formatted.
     *
     * @return string A textual representation of this RarArchive object. The content of this
     *          representation is unspecified.
     */
    public function  __toString()
    {
    }
}
/**
 * A RAR entry, representing a directory or a compressed file inside a RAR archive
 *
 * @link https://php.net/manual/en/class.rarentry.php
 */
final class RarEntry
{
    /**
     * If the return value of {@see RarEntry::getHostOs()} equals this constant, MS-DOS was used to add this entry.
     * Use instead of {@see RAR_HOST_MSDOS}.
     */
    const HOST_MSDOS = 0;
    /**
     * If the return value of {@see RarEntry::getHostOs()} equals this constant, OS/2 was used to add this entry.
     * Intended to replace {@see RAR_HOST_OS2}.
     */
    const HOST_OS2 = 1;
    /**
     * If the return value of {@see RarEntry::getHostOs()} equals this constant, Microsoft Windows was used to add this entry.
     * Intended to replace {@see RAR_HOST_WIN32}
     */
    const HOST_WIN32 = 2;
    /**
     * If the return value of {@see RarEntry::getHostOs()} equals this constant, an unspecified UNIX OS was used to add
     * this entry. Intended to replace {@see RAR_HOST_UNIX}.
     */
    const HOST_UNIX = 3;
    /**
     * If the return value of {@see RarEntry::getHostOs()} equals this constant, Mac OS was used to add this entry.
     */
    const HOST_MACOS = 4;
    /**
     * If the return value of {@see RarEntry::getHostOs()} equals this constant, BeOS was used to add this entry.
     * Intended to replace {@see RAR_HOST_BEOS}.
     */
    const HOST_BEOS = 5;
    /**
     * Bit that represents a Windows entry with a read-only attribute. To be used with {@see RarEntry::getAttr()} on
     * entries whose host OS is Microsoft Windows.
     */
    const ATTRIBUTE_WIN_READONLY = 1;
    /**
     * Bit that represents a Windows entry with a hidden attribute. To be used with {@see RarEntry::getAttr()} on
     * entries whose host OS is Microsoft Windows.
     */
    const ATTRIBUTE_WIN_HIDDEN = 2;
    /**
     * Bit that represents a Windows entry with a system attribute. To be used with {@see RarEntry::getAttr()} on
     * entries whose host OS is Microsoft Windows.
     */
    const ATTRIBUTE_WIN_SYSTEM = 4;
    /**
     * Bit that represents a Windows entry with a directory attribute (entry is a directory). To be used with
     * {@see RarEntry::getAttr()} on entries whose host OS is Microsoft Windows. See also
     * {@see RarEntry::isDirectory()}, which also works with entries that were not added in WinRAR.
     */
    const ATTRIBUTE_WIN_DIRECTORY = 16;
    /**
     * Bit that represents a Windows entry with an archive attribute. To be used with {@see RarEntry::getAttr()} on
     * entries whose host OS is Microsoft Windows.
     */
    const ATTRIBUTE_WIN_ARCHIVE = 32;
    /**
     * Bit that represents a Windows entry with a device attribute. To be used with {@see RarEntry::getAttr()} on
     * entries whose host OS is Microsoft Windows.
     */
    const ATTRIBUTE_WIN_DEVICE = 64;
    /**
     * Bit that represents a Windows entry with a normal file attribute (entry is NOT a directory). To be used with
     * {@see RarEntry::getAttr()} on entries whose host OS is Microsoft Windows. See also
     * {@see RarEntry::isDirectory()}, which also works with entries that were not added in WinRAR.
     */
    const ATTRIBUTE_WIN_NORMAL = 128;
    /**
     * Bit that represents a Windows entry with a temporary attribute. To be used with {@see RarEntry::getAttr()} on
     * entries whose host OS is Microsoft Windows.
     */
    const ATTRIBUTE_WIN_TEMPORARY = 256;
    /**
     * Bit that represents a Windows entry with a sparse file attribute (file is an NTFS sparse file). To be used with
     * {@see RarEntry::getAttr()} on entries whose host OS is Microsoft Windows.
     */
    const ATTRIBUTE_WIN_SPARSE_FILE = 512;
    /**
     * Bit that represents a Windows entry with a reparse point attribute (entry is an NTFS reparse point, e.g. a
     * directory junction or a mount file system). To be used with {@see RarEntry::getAttr()} on entries whose host OS
     * is Microsoft Windows.
     */
    const ATTRIBUTE_WIN_REPARSE_POINT = 1024;
    /**
     * Bit that represents a Windows entry with a compressed attribute (NTFS only). To be used with
     * {@see RarEntry::getAttr()} on entries whose host OS is Microsoft Windows.
     */
    const ATTRIBUTE_WIN_COMPRESSED = 2048;
    /**
     * Bit that represents a Windows entry with an offline attribute (entry is offline and not accessible). To be used
     * with {@see RarEntry::getAttr()} on entries whose host OS is Microsoft Windows.
     */
    const ATTRIBUTE_WIN_OFFLINE = 4096;
    /**
     * Bit that represents a Windows entry with a not content indexed attribute (entry is to be indexed). To be used
     * with {@see RarEntry::getAttr()} on entries whose host OS is Microsoft Windows.
     */
    const ATTRIBUTE_WIN_NOT_CONTENT_INDEXED = 8192;
    /**
     * Bit that represents a Windows entry with an encrypted attribute (NTFS only). To be used with
     * {@see RarEntry::getAttr()} on entries whose host OS is Microsoft Windows.
     */
    const ATTRIBUTE_WIN_ENCRYPTED = 16384;
    /**
     * Bit that represents a Windows entry with a virtual attribute. To be used with {@see RarEntry::getAttr()}
     * on entries whose host OS is Microsoft Windows.
     */
    const ATTRIBUTE_WIN_VIRTUAL = 65536;
    /**
     * Bit that represents a UNIX entry that is world executable. To be used with {@see RarEntry::getAttr()} on entries
     * whose host OS is UNIX.
     */
    const ATTRIBUTE_UNIX_WORLD_EXECUTE = 1;
    /**
     * Bit that represents a UNIX entry that is world writable. To be used with {@see RarEntry::getAttr()} on entries
     * whose host OS is UNIX.
     */
    const ATTRIBUTE_UNIX_WORLD_WRITE = 2;
    /**
     * Bit that represents a UNIX entry that is world readable. To be used with {@see RarEntry::getAttr()} on entries
     * whose host OS is UNIX.
     */
    const ATTRIBUTE_UNIX_WORLD_READ = 4;
    /**
     * Bit that represents a UNIX entry that is group executable. To be used with {@see RarEntry::getAttr()} on entries
     * whose host OS is UNIX.
     */
    const ATTRIBUTE_UNIX_GROUP_EXECUTE = 8;
    /**
     * Bit that represents a UNIX entry that is group writable. To be used with {@see RarEntry::getAttr()} on entries
     * whose host OS is UNIX.
     */
    const ATTRIBUTE_UNIX_GROUP_WRITE = 16;
    /**
     * Bit that represents a UNIX entry that is group readable. To be used with {@see RarEntry::getAttr()} on entries
     * whose host OS is UNIX.
     */
    const ATTRIBUTE_UNIX_GROUP_READ = 32;
    /**
     * Bit that represents a UNIX entry that is owner executable. To be used with {@see RarEntry::getAttr()} on entries
     * whose host OS is UNIX.
     */
    const ATTRIBUTE_UNIX_OWNER_EXECUTE = 64;
    /**
     * Bit that represents a UNIX entry that is owner writable. To be used with {@see RarEntry::getAttr()} on entries
     * whose host OS is UNIX.
     */
    const ATTRIBUTE_UNIX_OWNER_WRITE = 128;
    /**
     * Bit that represents a UNIX entry that is owner readable. To be used with {@see RarEntry::getAttr()} on entries
     * whose host OS is UNIX.
     */
    const ATTRIBUTE_UNIX_OWNER_READ = 256;
    /**
     * Bit that represents the UNIX sticky bit. To be used with {@see RarEntry::getAttr()} on entries whose host OS is
     * UNIX.
     */
    const ATTRIBUTE_UNIX_STICKY = 512;
    /**
     * Bit that represents the UNIX setgid attribute. To be used with {@see RarEntry::getAttr()} on entries whose host
     * OS is UNIX.
     */
    const ATTRIBUTE_UNIX_SETGID = 1024;
    /**
     * Bit that represents the UNIX setuid attribute. To be used with {@see RarEntry::getAttr()} on entries whose host
     * OS is UNIX.
     */
    const ATTRIBUTE_UNIX_SETUID = 2048;
    /**
     * Mask to isolate the last four bits (nibble) of UNIX attributes (_S_IFMT, the type of file mask). To be used with
     * {@see RarEntry::getAttr()} on entries whose host OS is UNIX and with the constants
     * {@see RarEntry::ATTRIBUTE_UNIX_FIFO}, {@see RarEntry::ATTRIBUTE_UNIX_CHAR_DEV},
     * {@see RarEntry::ATTRIBUTE_UNIX_DIRECTORY}, {@see RarEntry::ATTRIBUTE_UNIX_BLOCK_DEV},
     * {@see RarEntry::ATTRIBUTE_UNIX_REGULAR_FILE},
     * {@see RarEntry::ATTRIBUTE_UNIX_SYM_LINK} and {@see RarEntry::ATTRIBUTE_UNIX_SOCKET}.
     */
    const ATTRIBUTE_UNIX_FINAL_QUARTET = 61440;
    /**
     * Unix FIFOs will have attributes whose last four bits have this value. To be used with {@see RarEntry::getAttr()}
     * on entries whose host OS is UNIX and with the constant {@see RarEntry::ATTRIBUTE_UNIX_FINAL_QUARTET}.
     */
    const ATTRIBUTE_UNIX_FIFO = 4096;
    /**
     * Unix character devices will have attributes whose last four bits have this value. To be used with
     * {@see RarEntry::getAttr()} on entries whose host OS is UNIX and with the constant
     * {@see RarEntry::ATTRIBUTE_UNIX_FINAL_QUARTET}.
     */
    const ATTRIBUTE_UNIX_CHAR_DEV = 8192;
    /**
     * Unix directories will have attributes whose last four bits have this value. To be used with
     * {@see RarEntry::getAttr()} on entries whose host OS is UNIX and with the constant
     * {@see RarEntry::ATTRIBUTE_UNIX_FINAL_QUARTET}.
     *
     * See also {@see RarEntry::isDirectory()}, which also works with entries that were added in other operating
     * systems.
     */
    const ATTRIBUTE_UNIX_DIRECTORY = 16384;
    /**
     * Unix block devices will have attributes whose last four bits have this value. To be used with
     * {@see RarEntry::getAttr()} on entries whose host OS is UNIX and with the constant
     * {@see RarEntry::ATTRIBUTE_UNIX_FINAL_QUARTET}.
     */
    const ATTRIBUTE_UNIX_BLOCK_DEV = 24576;
    /**
     * Unix regular files (not directories) will have attributes whose last four bits have this value. To be used with
     * {@see RarEntry::getAttr()} on entries whose host OS is UNIX and with the constant
     * {@see RarEntry::ATTRIBUTE_UNIX_FINAL_QUARTET}. See also {@see RarEntry::isDirectory()}, which also works with
     * entries that were added in other operating systems.
     */
    const ATTRIBUTE_UNIX_REGULAR_FILE = 32768;
    /**
     * Unix symbolic links will have attributes whose last four bits have this value. To be used with
     * {@see RarEntry::getAttr()} on entries whose host OS is UNIX and with the constant
     * {@see RarEntry::ATTRIBUTE_UNIX_FINAL_QUARTET}.
     */
    const ATTRIBUTE_UNIX_SYM_LINK = 40960;
    /**
     * Unix sockets will have attributes whose last four bits have this value. To be used with
     * {@see RarEntry::getAttr()} on entries whose host OS is UNIX and with the constant
     * {@see RarEntry::ATTRIBUTE_UNIX_FINAL_QUARTET}.
     */
    const ATTRIBUTE_UNIX_SOCKET = 49152;
    /**
     * Extract entry from the archive
     *
     * extracts the entry's data. It will create new file in the specified dir with the name identical to the entry's
     * name, unless the second argument is specified.
     *
     * @link https://php.net/manual/en/rarentry.extract.php
     *
     * @param string $dir Path to the directory where files should be extracted. This parameter is considered if and
     *      only if filepath is not. If both parameters are empty an extraction to the current directory
     *      will be attempted.
     * @param string $filepath Path (relative or absolute) containing the directory and filename of the extracted file.
     *      This parameter overrides both the parameter dir and the original file name.
     * @param string $password The password used to encrypt this entry. If the entry is not encrypted, this value will
     *      not be used and can be omitted. If this parameter is omitted and the entry is encrypted, the password
     *      given to rar_open(), if any, will be used. If a wrong password is given, either explicitly or implicitly
     *      via rar_open(), CRC checking will fail and this method will fail and return FALSE. If no password is given
     *      and one is required, this method will fail and return FALSE. You can check whether an entry is encrypted
     *      with {@see RarEntry::isEncrypted()}.
     * @param bool $extended_data If TRUE, extended information such as NTFS ACLs and Unix owner information will be
     *      set in the extract files, as long as it's present in the archive.
     *
     * @return TRUE on success or FALSE on failure
     */
    public function  extract($dir, $filepath = "", $password = null, $extended_data = false)
    {
    }
    /**
     * Get attributes of the entry
     *
     * Returns the OS-dependent attributes of the archive entry
     *
     * @link https://php.net/manual/en/rarentry.getattr.php
     *
     * @return int the attributes or FALSE on error
     */
    public function getAttr()
    {
    }
    /**
     * Get CRC of the entry
     *
     * Returns an hexadecimal string representation of the CRC of the archive entry.
     *
     * @link https://php.net/manual/en/rarentry.getcrc.php
     *
     * @return string the CRC of the archive entry or FALSE on error
     */
    public function getCrc()
    {
    }
    /**
     * Get entry last modification time
     *
     * @link https://php.net/manual/en/rarentry.getfiletime.php
     *
     * @return string entry last modification time as string in format YYYY-MM-DD HH:II:SS, or FALSE on errors
     */
    public function getFileTime()
    {
    }
    /**
     * Get entry host OS
     *
     * Returns the code of the host OS of the archive entry
     *
     * @link https://php.net/manual/en/rarentry.gethostos.php
     *
     * @return int the code of the host OS, or FALSE on error
     */
    public function getHostOs()
    {
    }
    /**
     * Get pack method of the entry
     *
     * returns number of the method used when adding current archive entry
     *
     * @link https://php.net/manual/en/rarentry.getmethod.php
     *
     * @return int the method number or FALSE on error
     */
    public function getMethod()
    {
    }
    /**
     * Get name of the entry
     *
     * Returns the name (with path) of the archive entry.
     *
     * @link https://php.net/manual/en/rarentry.getname.php
     *
     * @return string the entry name as a string, or FALSE on error.
     */
    public function getName()
    {
    }
    /**
     * Get packed size of the entry
     *
     * @link https://php.net/manual/en/rarentry.getpackedsize.php
     *
     * @return int the packed size, or FALSE on error
     */
    public function getPackedSize()
    {
    }
    /**
     * Get file handler for entry
     *
     * Returns a file handler that supports read operations. This handler provides on-the-fly decompression for
     * this entry. The handler is not invalidated by calling {@see rar_close()}.
     *
     * @link https://php.net/manual/en/rarentry.getstream.php
     *
     * @param string $password The password used to encrypt this entry. If the entry is not encrypted, this value will
     *      not be used and can be omitted. If this parameter is omitted and the entry is encrypted,
     *      the password given to {@see rar_open()}, if any, will be used. If a wrong password is given, either
     *      explicitly or implicitly via {@see rar_open()}, this method's resulting stream will produce wrong output.
     *      If no password is given and one is required, this method will fail and return FALSE. You can check
     *      whether an entry is encrypted with {@see RarEntry::isEncrypted()}.
     *
     * @return resource file handler or FALSE on failure
     */
    public function getStream($password = '')
    {
    }
    /**
     * Get unpacked size of the entry
     * @link https://php.net/manual/en/rarentry.getunpackedsize.php
     * @return int the unpacked size, or FALSE on error
     */
    public function getUnpackedSize()
    {
    }
    /**
     * Get minimum version of RAR program required to unpack the entry
     *
     * Returns minimum version of RAR program (e.g. WinRAR) required to unpack the entry. It is encoded as
     * 10 * major version + minor version.
     *
     * @link https://php.net/manual/en/rarentry.getversion.php
     *
     * @return int the version or FALSE on error
     */
    public function getVersion()
    {
    }
    /**
     * Test whether an entry represents a directory
     *
     * @link https://php.net/manual/en/rarentry.isdirectory.php
     *
     * @return bool TRUE if this entry is a directory and FALSE otherwise.
     */
    public function isDirectory()
    {
    }
    /**
     * Test whether an entry is encrypted
     *
     * @link https://php.net/manual/en/rarentry.isencrypted.php
     *
     * @return bool TRUE if the current entry is encrypted and FALSE otherwise
     */
    public function isEncrypted()
    {
    }
    /**
     * Get text representation of entry
     *
     * Returns a textual representation for this entry. It includes whether the entry is a file or a directory
     * (symbolic links and other special objects will be treated as files), the UTF-8 name of the entry and its CRC.
     * The form and content of this representation may be changed in the future, so they cannot be relied upon.
     *
     * @link https://php.net/manual/en/rarentry.tostring.php
     *
     * @return string A textual representation for the entry
     */
    public function __toString()
    {
    }
}
/**
 * This class serves two purposes:
 * it is the type of the exceptions thrown by the RAR extension functions and methods and it allows, through static
 * methods to query and define the error behaviour of the extension, i.e., whether exceptions are thrown or only
 * warnings are emitted.<br>
 * The following error codes are used:<br><ul>
 * <li>-1 - error outside UnRAR library</li>
 * <li>11 - insufficient memory</li>
 * <li>12 - bad data</li>
 * <li>13 - bad archive</li>
 * <li>14 - unknown format</li>
 * <li>15 - file open error</li>
 * <li>16 - file create error</li>
 * <li>17 - file close error</li>
 * <li>18 - read error</li>
 * <li>19 - write error</li>
 * <li>20 - buffer too small</li>
 * <li>21 - unkown RAR error</li>
 * <li>22 - password required but not given</li>
 * </ul>
 *
 * @link https://php.net/manual/en/class.rarexception.php
 */
final class RarException extends Exception
{
    /**
     * Check whether error handling with exceptions is in use
     *
     * @link https://php.net/manual/en/rarexception.isusingexceptions.php
     *
     * @return bool TRUE if exceptions are being used, FALSE otherwise
     */
    public static function isUsingExceptions()
    {
    }
    /**
     * Activate and deactivate error handling with exceptions
     *
     * @link https://php.net/manual/en/rarexception.setusingexceptions.php
     *
     * @param bool $using_exceptions Should be TRUE to activate exception throwing, FALSE to deactivate (the default)
     */
    public static function setUsingExceptions($using_exceptions)
    {
    }
}
