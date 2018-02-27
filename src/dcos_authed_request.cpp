/*
Tool for making authorized REST API requests to DCOS

Setup:
    sudo apt install libjson0 libjson0-dev libjson-glib-1.0-0 libjson-glib-dev libcurl-dev
    g++ -Wall -O3 -std=c++14 -o dcos_authed_request dcos_authed_request.cpp -ljson -lcurl

    Create encrypted token with Crypt::encrypt(<token>)

    Avoid stealing and using binary
        If server is physically taken away
            Binary will be kept in RAM only -> if server is power off -> binary erases
            sudo chown root:root dcos_authed_request
            sudo chmod 755 dcos_authed_request
            sudo mv dcos_authed_request /dev/shm
            sudo ln -s /dev/shm/dcos_authed_request /usr/bin/dcos_authed_request

        Prevent to run binary on other machines
            If server system ID differs from the expected in binary -> abort execution
            Compile binary with hardware system ID and uncomment hardware validation

Usage:
    dcos_authed_request '{"method":"<http-method>","url":"<request-url>","post":"<post-data>"}'
    dcos_authed_request '{"method":"GET","url":"http://<dcos-host>/service/chronos/v1/scheduler/jobs/summary","post":""}' | jq

    From PHP
        $response = `dcos_authed_request <json-request>`;
*/

#include <iostream>
#include <string>
#include <json/json.h>
#include <curl/curl.h>

using std::string;
using std::cout;
using std::cerr;

// Must not be kept as a plain string, as the strings in compiled binary being kept as is
const string HW_SYSTEM_ID_ENC = R"(?5OL;?8K#;O7=#:L?>#66>K#7J:9>L3M8H>2)";
const string AUTH_TOKEN_ENC   = R"(WwD>kVOgAgDEX?_gVMDflImgAgDG[tG?@gD2 kwDbkFOgAdK:CJ[=CtevCJ_}G`X~TMG8Gc@wkVL>l<8zWY;OT<?foYy{W<7zG`> <OBb7bHDW6G|g|i_>w}<@bW[CGkkt:|xdVOXG>@tbxU)";

static int curlWriter(char* data, size_t size, size_t nmemb, string* writerData) {
    if (writerData == NULL) {
        return 0;
    }
    writerData->append(data, size * nmemb);
    return size * nmemb;
}

// Crypt

class Crypt {
public:
    static const string encrypt(const string str) {
        string encrypted;
        for (int i = 0; i < str.length(); i++) {
            encrypted += (str[i] ^ CRYPT_SALT);
        }
        return encrypted;
    }

    static const string decrypt(const string encrypted) {
        string str;
        for (int i = 0; i < encrypted.length(); i++) {
            str += (encrypted[i] ^ CRYPT_SALT);
        }
        return str;
    }
    
private:
    static const int CRYPT_SALT = 14;
};

// ValidateHardware

class ValidateHardware {
public:
    ValidateHardware();
    ~ValidateHardware();
    bool validate();
    
private:
    string execShellCmd(const char* cmd);
};

ValidateHardware::ValidateHardware() { }
ValidateHardware::~ValidateHardware() { }

bool ValidateHardware::validate() {
    if (execShellCmd(R"(sudo dmidecode -t 1 | grep UUID | sed 's/\s*UUID:\s*\([A-Z0-9-]*\)\s*/\1/')") != Crypt::decrypt(HW_SYSTEM_ID_ENC)) {
        return 0;
    }
    return 1;
}

string ValidateHardware::execShellCmd(const char* cmd) {
    FILE* fh = popen(cmd, "r");
    if (!fh) {
        cerr << "Failed to exec command [" << cmd << "]";
        exit(EXIT_FAILURE);
    }
    string res;
    char buf[1024];
    while (fgets(buf, sizeof buf, fh)) {
        res += buf;
    }
    pclose(fh);
    res.pop_back(); // Remove tail "\n"
    return res;
}

// RequestInput

class RequestInput {
public:
    RequestInput(char* requestJson);
    ~RequestInput();
    const char* getMethod();
    const char* getUrl();
    const char* getPostData();
    const json_object* getHeaders();

private:
    const char* method;
    const char* url;
    const char* postData;
};

RequestInput::RequestInput(char* requestJson) {
    if (!requestJson) {
        cerr << "Input request json is empty\n";
        exit(EXIT_FAILURE);
    }
    
    json_object* jRequest = json_tokener_parse(requestJson);
    if (!jRequest) {
        cerr << "Failed to parse input request json\n";
        exit(EXIT_FAILURE);
    }
    
    method = json_object_get_string(json_object_object_get(jRequest, "method"));
    if (!method) {
        cerr << "Invalid method\n";
        exit(EXIT_FAILURE);
    }
    
    url = json_object_get_string(json_object_object_get(jRequest, "url"));
    if (!url) {
        cerr << "Invalid url\n";
        exit(EXIT_FAILURE);
    }
    
    postData = json_object_get_string(json_object_object_get(jRequest, "post"));
};

RequestInput::~RequestInput() { }

const char* RequestInput::getMethod() {
    return method;
}

const char* RequestInput::getUrl() {
    return url;
}

const char* RequestInput::getPostData() {
    return postData;
}

// DcosAuthedRequest

class DcosAuthedRequest {
public:
    DcosAuthedRequest();
    ~DcosAuthedRequest();
    string* exec(const char* method, const char* url, const char* postData);
    
private:
    CURL* conn;
    CURLcode code;
    string buffer;
    char errorBuffer[CURL_ERROR_SIZE];
    
    const string getAuthToken();
};

DcosAuthedRequest::DcosAuthedRequest() { }
DcosAuthedRequest::~DcosAuthedRequest() { };

string* DcosAuthedRequest::exec(const char* method, const char* url, const char* postData) {
    conn = curl_easy_init();
    if (!conn) {
        cerr << "Failed to init curl connection\n";
        exit(EXIT_FAILURE);
    }
    
    struct curl_slist *headerChunk = NULL;
    headerChunk = curl_slist_append(headerChunk, ("Authorization: token=" + getAuthToken()).c_str());
    
    if (curl_easy_setopt(conn, CURLOPT_ERRORBUFFER, errorBuffer) != CURLE_OK) {
        cerr << "Failed to set error buffer [" << errorBuffer << "]\n";
        exit(EXIT_FAILURE);
    }
    
    if (curl_easy_setopt(conn, CURLOPT_WRITEFUNCTION, curlWriter) != CURLE_OK) {
        cerr << "Failed to set writer [" << errorBuffer << "]\n";
        exit(EXIT_FAILURE);
    }
    
    if (curl_easy_setopt(conn, CURLOPT_WRITEDATA, &buffer) != CURLE_OK) {
        cerr << "Failed to set write buffer [" << errorBuffer << "]\n";
        exit(EXIT_FAILURE);        
    }
    
    if (curl_easy_setopt(conn, CURLOPT_VERBOSE, 0) != CURLE_OK) {
        cerr << "Failed to set verbose [" << errorBuffer << "]\n";
        exit(EXIT_FAILURE);
    }
    
    if (curl_easy_setopt(conn, CURLOPT_FOLLOWLOCATION, 1) != CURLE_OK) {
        cerr << "Failed to set follow location [" << errorBuffer << "]\n";
        exit(EXIT_FAILURE);
    }
  
    if (curl_easy_setopt(conn, CURLOPT_TIMEOUT, 30) != CURLE_OK) {
        cerr << "Failed to set timeout [" << errorBuffer << "]\n";
        exit(EXIT_FAILURE);
    }
    
    if (curl_easy_setopt(conn, CURLOPT_SSL_VERIFYPEER, 0) != CURLE_OK) {
        cerr << "Failed to set ssl verifypeer [" << errorBuffer << "]\n";
        exit(EXIT_FAILURE);
    }
    
    if (curl_easy_setopt(conn, CURLOPT_SSL_VERIFYHOST, 0) != CURLE_OK) {
        cerr << "Failed to set ssl verifyhost [" << errorBuffer << "]\n";
        exit(EXIT_FAILURE);
    }
    
    if (curl_easy_setopt(conn, CURLOPT_URL, url) != CURLE_OK) {
        cerr << "Failed to set url [" << errorBuffer << "]\n";
        exit(EXIT_FAILURE);
    }
    
    if (curl_easy_setopt(conn, CURLOPT_CUSTOMREQUEST, method)) {
        cerr << "Failed to set http method [" << errorBuffer << "]\n";
        exit(EXIT_FAILURE);
    }
    
    if (curl_easy_setopt(conn, CURLOPT_HTTPHEADER, headerChunk)) {
        cerr << "Failed to set headers [" << errorBuffer << "]\n";
        exit(EXIT_FAILURE);
    }
    
    if (postData) {
        if (curl_easy_setopt(conn, CURLOPT_POSTFIELDS, postData)) {
            cerr << "Failed to set post data [" << errorBuffer << "]\n";
            exit(EXIT_FAILURE);
        }
    }
    
    code = curl_easy_perform(conn);
    curl_easy_cleanup(conn);

    if (code != CURLE_OK) {
        cerr << "Request failed with error [" << code << ": " << errorBuffer << "]\n";
        exit(EXIT_FAILURE);
    }
    
    return &buffer;
};

const string DcosAuthedRequest::getAuthToken() {
    return Crypt::decrypt(AUTH_TOKEN_ENC);
}

int main(int argc, char* argv[]) {
    if (argc != 2) {
        cerr << "Usage: " << argv[0] << " {\"method\":\"<http-method>\", \"url\":\"<request-url>\", \"post\":\"<post-data>\"}\n";
        exit(EXIT_FAILURE);
    }

    // Uncomment for hardware validation
    //ValidateHardware vh;
    //if (!vh.validate()) {
    //    exit(EXIT_SUCCESS);
    //}
    
    RequestInput input(argv[1]);
    
    DcosAuthedRequest request;
    string* response = request.exec(input.getMethod(), input.getUrl(), input.getPostData());
    
    cout << *response;
    
    return EXIT_SUCCESS;
}
