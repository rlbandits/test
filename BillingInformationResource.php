<?php

namespace App\Modules\BillingInformation\BillingInformation\Services;

use Response;
use App\Libraries\StatusHelper;
use App\Libraries\ListHelper;
use App\Libraries\AuthHelper;
use App\Libraries\FileHelper;
use App\Libraries\PdfHelper;
use App\Contracts\FileInterface;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use \Carbon\Carbon;
use App\Modules\Store\Store\Models\Store;
use App\Modules\BillingInformation\BillingInformation\Contracts\BillingInformationResourceInterface;
use App\Modules\BillingInformation\BillingInformation\Models\BillingInformation;
use App\Modules\BillingInformation\BillingInformation\Models\BillingInformationHdr;
use App\Modules\BillingInformation\BillingInformation\Models\BillingInformationDtl;
use App\Modules\ItemMapping\ItemMapping\Models\ItemMapping;
use App\Modules\Pod\Pod\Models\Pod;
use App\Modules\Shipment\Shipment\Models\ShipmentDelivery;
use App\Modules\Shipment\Shipment\Models\ShipmentAssignment;
use App\Modules\TruckPersonnel\TruckPersonnel\Models\TruckPersonnelTeam;
use App\Modules\TruckPersonnel\TruckPersonnel\Models\TruckPersonnel;
use App\Modules\TruckPersonnel\TruckPersonnel\Models\TruckTeamHelper;


use Ramsey\Uuid\Uuid;
use Excel;

class BillingInformationResource implements BillingInformationResourceInterface
{

    public $module;

    public $user_id;

    public function __construct(Request $request,FileInterface $file)
    {
        $this->file = $file;
        //get user_id based on X-Authorization
        $this->user_id = AuthHelper::getUserDataUsingAuthToken($request->header('X-Auth-Token'));
    }

    public function getAll()
    {
        $this->module = BillingInformation::all();
        return $this->module;
    }

    public function getDropdown()
    {
        $this->module = BillingInformation::select('account_group_id', 'account_group_name')->get();
        return $this->module;
    }

    /**
     * Search element resource by
     * @param  Request $request
     * @return array
     */
    public function searchModuleByValue($search_param, $page_size, $page, $sorting = [], $relationship = [], $select = [])
    {

        // Check for valid pagination
        $pagination = ListHelper::validatePagination($page_size, $page);

        $billing_info = BillingInformation::with($relationship)->search($search_param);

        if(!empty($select)) {
            $billing_info = $billing_info->select($select);
        }

        if (count($sorting)) {
            $billing_info = $billing_info->orderBy($sorting['field'], $sorting['sort_order']);
        }

        // Prepare pagination
        if(($pagination == true))
            $billing_info = $billing_info->paginate($page_size);
        else
            $billing_info->get();

        // Check model resource response
        if (count($billing_info) > 0) {
            return $response = [
                'code'          => '200',
                'status'        => "SC001",
                'data'          => $billing_info->toArray()
            ];
        }
        else {
            return $response = [
                'code'          => '404',
                'status'        => "NF002",
                'message'       => 'Resource not found.'
            ];
        }
    }

     public function storeBillingInformation(Request $request)
    {
        return [];
    }

    public function downloadBillingInformation($biling_info_uuid = 0)
    {
        $this->billing = BillingInformation::whereBillingInformationUuid($biling_info_uuid)->first();
        if($this->billing){
           return $this->file->download(public_path().'/uploads/csv/billing/Extracted_'.$this->billing->billing_information_filename, $this->billing->billing_information_filename);

        }else{
            return StatusHelper::getErrorResponseStatus();
        }
    }

    public function showBillingInformation($biling_info_uuid = 0)
    {
        $this->billing_info = BillingInformation::whereBillingInformationUuid($biling_info_uuid)->first();
        if($this->billing_info){
            return StatusHelper::getCreatedResponseStatus($this->billing_info);
        }
            return StatusHelper::getErrorResponseStatus();
    }

    public function updateBillingInformation(Request $request, $user_uuid = 0)
    {

        return [];

    }

    public function deleteBillingInformation($user_uuid = 0)
    {
      return [];
    }

    public static function processBilling($data = [] , $filename = '')
    {
        //check if filename already exists
        $billing_hdr = BillingInformation::where('billing_information_filename', '=', $filename)->first();
        if ($billing_hdr) {
            $error = [
                'field_name' => 'File Uploaded',
                'message' => 'CSV file already exists: '.$filename,
            ];

            $response = [
                'code' => '422',
                'status' => "UE003",
                'message' => [$error]
            ];
            return $response;

        } else {
            $sold_to_code = "";//TO HOLD VALUE FOR EFGR
            $ship_to_code = "";//TO HOLD VALUE FOR ACTUAL STORE CODE
            if (!empty($data)) {
                $data = Excel::load($data, function($reader) {
                    $reader->noHeading = true;
                }, 'ISO-8859-1')->get();
                $row_count = $data->count();
                $parsed_error = [];
                $error = [];

                // added by luis 6192019
                // checking for non-pylon store
                $billing_hdr_row = $data[0];
                $billing_dtl_first_row = $data[1];
                // 8 = db_billing_hdr_fields.sold_to_code
                // 11 = db_billing_dtl_fields.ship_to_code
                $isEFGR = ($billing_hdr_row[8] != $billing_dtl_first_row[11]) ? 1 : 0;

                foreach($data as $row => $value) {
                    if ($value[0] != 'HDR') {
                        if ($value[0] != 'DTL') {
                            $error = [
                                'field_name' => 'File Uploaded',
                                'message' => 'CSV file '.$filename.
                                ' format is invalid. Column 1 value is invalid!',
                            ];
                            $parsed_error[] = $error;
                        }
                    }
                    $row_number = ($row == 0) ? 1 : $row + 1;
                    if ($value[0] == 'HDR') {
                        $billing_header = new BillingInformationHdr();
                        foreach(config('app.db_billing_hdr_fields') as $index => $field) {

                            if ($value[$index] !== null) {

                                if ($field == 'delivery_number') {
                                    //call Billing Information Header model for checking if delivery number is existing
                                    $is_exist = BillingInformationHdr::whereDeliveryNumber($value[$index])->first();
                                    if ($is_exist) {
                                        $error = [
                                            'field_name' => $field,
                                            'message' => 'Delivery Number already exist. Error in row number '.$row_number.
                                            ' in file '.$filename,
                                        ];
                                        $parsed_error[] = $error;
                                    }
                                }

                                if ($field == 'delivery_date' || $field == 'po_date') {
                                    $billing_header->$field = date('Y-m-d', strtotime($value[$index]));
                                } else {
                                    $billing_header->$field = $value[$index];
                                }

                                // added by luis 6192019
                                // checking for non-pylon store
                                if(!$isEFGR)
                                {
                                    //checking if store id is valid
                                    if ($field == 'sold_to_code' || $field == 'ship_to_code') {
                                        $is_exist = Store::where("selecta_code_id", "=", $value[$index])->first();
                                        if (!$is_exist) {
                                            $error = [
                                                'field_name' => $field,
                                                'message' => 'Please check if store code already created on store management module. Error in row number '.$row_number.
                                                ' in filename '.$filename,
                                            ];

                                            $parsed_error[] = $error;
                                        }
                                    }
                                }
                                if ($field == 'sold_to_code') {
                                    $sold_to_code = (string)$value[$index];
                                }

                            } else {
                                //error log
                                if ($field !== 'customer_po_reference_number') {
                                    $error = [
                                        'field_name' => $field,
                                        'message' => 'Must be required on row number '.$row_number.
                                        ' in filename '.$filename,
                                    ];
                                    $parsed_error[] = $error;
                                }
                            }
                        }
                    } else if ($value[0] == 'DTL') {
                        foreach(config('app.db_billing_dtl_fields') as $index => $field) {
                            if($field == 'ship_to_code'){
                                $ship_to_code = $value[$index];break;
                            }
                        }
                    }
                }
            }
            Log::info("SOLD TO CODE: ".$sold_to_code);//DIFFERENT VALUE FROM ship_to_code IF EFGR
            Log::info("SHIP TO CODE: ".$ship_to_code);
            $upload_response = FileHelper::moveFileDestination(public_path().
                "/uploads/csv/billing_extracted", "Extracted_".$filename, public_path().
                "/uploads/csv/billing");

            //generate UUID
            $uuid1 = Uuid::uuid1();
            $csv_data_file = BillingInformation::create([
                'billing_information_uuid' => $uuid1->toString(),
                'billing_information_filename' => $filename,
                'parsed_date' => Carbon::now()->toDateTimeString(),
                'upload_date' => Carbon::now()->toDateTimeString(),
                'synched_status' => 1,
                'is_failed' => ($parsed_error) ? 1 : 0,
                'error_log' => ($parsed_error) ? json_encode($parsed_error) : NULL, // response()->json($parsed_error)
            ]);
            if ($parsed_error) {
                $response = [
                    'code' => '422',
                    'status' => "UE003",
                    'message' => $parsed_error
                ];
                return $response;
            } else {

                if ($csv_data_file) {
                    $billing_header->billing_information_id = $csv_data_file->billing_information_id;
                    $billing_header->sold_to_code = $sold_to_code;
                    if ($billing_header->save()) {
                        $count = BillingInformationHdr::whereDeliveryDate($billing_header->delivery_date)->whereSoldToCode($ship_to_code)->count();
                        $seq_per_day = ($count == 1) ? 0 : $count - 1;
                        Log::info("Sequence per day  : ".$seq_per_day);
                        $store = Store::whereSelectaCodeId($ship_to_code)->first();
                        $row_dtl = 0;
                        $count_total_rows = 0;
                        foreach($data as $row_dtl => $value_dtl) {
                            if ($value_dtl[0] == 'DTL') {
                                $billing_detail = new BillingInformationDtl();
                                $billing_detail->billing_information_hdr_id = $billing_header->billing_information_hdr_id;
                                foreach(config('app.db_billing_dtl_fields') as $index => $field) {
                                    // if($field == 'seq_num') {
                                    //     $billing_detail->$field = (string)"NULL";
                                    // } else {
                                        $billing_detail->$field = $value_dtl[$index];
                                    // }
                                }
                                $billing_detail->save();
                                $count_total_rows++;
                            }
                        }

                        $billing_information_dtl = BillingInformationDtl::where('billing_information_hdr_id', '=', $billing_header->billing_information_hdr_id)->get();
                        //get shipment delivery details
                        $shipment_delivery = ShipmentDelivery::whereDeliveryNumber($billing_header->delivery_number)->orderByRaw('shipment_delivery_id DESC')->first();
                        $shipment_assignment = ShipmentAssignment::where('shipment_id', '=', $shipment_delivery->shipment_id)->where('dispatch_time', '<>', NULL)->where('is_active', '=', 1)->first();
                        $truck_personnel_team = TruckPersonnelTeam::whereTruckPersonnelTeamId($shipment_assignment->truck_personnel_team_id)->first();
                        $truck_personnel = TruckPersonnel::whereTruckPersonnelId($truck_personnel_team->truck_personnel_id)->first();
                        $truck_team_helper = TruckTeamHelper::whereTruckPersonnelTeamId($shipment_assignment->truck_personnel_team_id)->get();

                        $details = [];

                        $pod_invoice_number = ($billing_header) ? $billing_header->invoice_number : '';

                        foreach(config('app.db_dlv_hdr_fields') as $index => $field) {
                            if ($field == 'H') {
                                $value = 'H';
                            }
                            elseif($field == 'Supplier') {
                                $value = 'SD1';
                            }
                            elseif($field == 'Delivery Date') {
                                //$value = ($billing_header) ? date('Y-m-d',strtotime($billing_header->delivery_date)) : '0000-00-00';
                                $value = ($billing_header) ? date('Y-m-d', strtotime('-1 day', strtotime($billing_header->invoice_date))) : '0000-00-00';
                            }
                            elseif($field == 'Store') {
                                $value = $store->account_group_store_id;
                            }
                            elseif($field == 'PO Number') {
                                //$value = ($billing_header) ? $billing_header->customer_po_reference_number : '0';

                                if (!empty($billing_header->customer_po_reference_number)) {
                                    $value = $billing_header->customer_po_reference_number;
                                } else {
                                    $value = (string) "0";
                                }
                            }
                            elseif($field == 'Total Items') {
                                $value = $count_total_rows;
                            }
                            elseif($field == 'Constant Value') {
                                $value = '1';
                            } else {
                                $value = '';
                            }
                            $hdata[] = $value;

                        }

                        Log::info('CSV Data: '.json_encode($hdata));

                        $csv_data[] = $hdata;
                        $iteratedLineno = 1;
                        foreach($billing_information_dtl as $dtl => $val) {
                                //$item_mapping = ItemMapping::whereSelectaItemCode($billing_detail->material_code)->first();
                                $item_mapping = ItemMapping::where('selecta_item_code', '=', $val['material_code'])->where('store_id', '=', $store->store_id)->where('effectivity_start_date', '<=', Carbon::now())->where('effectivity_end_date', '>=', Carbon::now())->orderby('created_at', 'DESC')->first();

                                if(!in_array((string)$sold_to_code, config('app.efgr'))) {//EFGR
                                    $qty_conversion = ($item_mapping->uom_conversion <> 0) ? $item_mapping->uom_conversion : 1;
                                    $value_cost = ($val['list_price_after_tax'] / $val['invoiced_qty']);
                                    $qty_ratio = ($value_cost / $qty_conversion);
                                    $cost = round($qty_ratio, 2);
                                    $invoiced_qty = ($val['invoiced_qty'] * $qty_conversion);
                                    $unit_cost = ($val['list_price_after_tax'] / $invoiced_qty);
                                } else {
                                    $qty_conversion = ($item_mapping->uom_conversion <> 0) ? $item_mapping->uom_conversion : 1;
                                    $value_cost = 0;
                                    $qty_ratio = 0;
                                    $cost = 0;
                                    $invoiced_qty = ($val['invoiced_qty'] * $qty_conversion);
                                    $unit_cost = 0;
                                    $item_mapping->retail_price = 0;
                                }
                                    

                                //create POD PDF items
                                $details[] = [
                                    "item_code" => $item_mapping->account_group_material_code,
                                    "description" => $val['material_description'],
                                    "unit_cost" => $unit_cost,
                                    "retail_price" => ($item_mapping) ? $item_mapping->retail_price : '',
                                    "quantity_received" => $invoiced_qty,
                                ];

                            if(!in_array((string)$sold_to_code, config('app.efgr'))) {//EFGR

                                Log::info("DLV Items : ".json_encode($item_mapping));

                                foreach(config('app.db_dlv_dtl_fields') as $index => $field) {
                                    if ($field == 'D') {
                                        $value = 'D';
                                    }
                                    elseif($field == 'Supplier') {
                                        $value = 'SD1';
                                    }
                                    elseif($field == 'Category') {
                                        $value = ($item_mapping) ? $item_mapping->account_group_material_category : '';
                                    }
                                    elseif($field == 'Item Code') {
                                        $value = ($val) ? $item_mapping->account_group_material_code : '';
                                    }
                                    elseif($field == 'Invoice Number') {
                                        $value = ($billing_header) ? $billing_header->invoice_number : '';
                                    }
                                    elseif($field == 'Delivery Date') {
                                        //$value = ($billing_header) ? date('Y-m-d',strtotime($billing_header->delivery_date)) : '0000-00-00';
                                        $value = ($billing_header) ? date('Y-m-d', strtotime('-1 day', strtotime($billing_header->invoice_date))) : '0000-00-00';
                                    }
                                    elseif($field == 'QTY') {
                                        $value = ($val) ? $invoiced_qty : '';
                                    }
                                    elseif($field == 'Disc_Cost') {
                                        $value = ($val) ? round($cost,2) : '0.00';
                                    }
                                    elseif($field == 'Cost') {
                                        $value = ($val) ? round($cost,2) : '0.00';
                                    }
                                    elseif($field == 'Retail') {
                                        $value = ($item_mapping) ? $item_mapping->retail_price : '0.00';
                                    } else {
                                        $value = '';
                                    }
                                    $ddata[] = $value;
                                }
                                $csv_data[] = $ddata;
                                $ddata = [];

                                /* trr creation */
                                foreach(config('app.db_trr_fields') as $index => $field_trr) {
                                    if ($field_trr == 'storeno') {
                                        $value_trr = '166';
                                    }
                                    elseif($field_trr == 'trndate') {
                                        $value_trr = date('m/d/Y', strtotime($billing_header->invoice_date));
                                    }
                                    elseif($field_trr == 'supp_code') {
                                        $value_trr = 'SD1'; // should be string as SD1
                                    }
                                    elseif($field_trr == 'invoice') {
                                        $value_trr = ($billing_header) ? $billing_header->invoice_number : '';
                                    }
                                    elseif($field_trr == 'itemcode') {
                                        $value_trr = ($item_mapping) ? $item_mapping->account_group_material_code : '';
                                    }
                                    elseif($field_trr == 'catcode') {
                                        $value_trr = '22';
                                    }
                                    elseif($field_trr == 'qty') {
                                        // $value_trr = ($billing_detail->invoiced_qty * $item_mapping->uom_conversion);
                                        $value_trr = ($val) ? $invoiced_qty : '';
                                    }
                                    elseif($field_trr == 'retail') {
                                        $value_trr = round($item_mapping->retail_price, 2);
                                    }
                                    elseif($field_trr == 'cost') {
                                        // $value_trr = round(($billing_detail->list_price_after_tax / $item_mapping->uom_conversion), 2);
                                        $value_trr = round($cost,2); // computation of how the cost of DLV was generated.
                                    }
                                    elseif($field_trr == 'vat') {
                                        $value_trr = '0.00';
                                    }
                                    elseif($field_trr == 'totcost') {
                                        // Column 11 (total cost) = cost (col9) * piece[qty] (col7)
                                        $value_trr = round($cost * $invoiced_qty, 2); 
                                    }
                                    elseif($field_trr == 'totretail') {
                                        // Column 12 (total retail) = retail (col8) * piece[qty] (col7)
                                        $value_trr = round($item_mapping->retail_price * $invoiced_qty, 2);
                                    }
                                    elseif($field_trr == 'lineno') {
                                        $value_trr = $iteratedLineno;
                                    }
                                    elseif($field_trr = 'disc') {
                                        $value_trr = '0.00';
                                    }
                                    elseif($field_trr == 'ponum') {
                                        $value_trr = ($billing_header == '') ? $billing_header->po_number : 0;
                                    }
                                    elseif($field_trr == 'docno') {
                                        $value_trr = '0';
                                    }
                                    $trr_hdata[] = $value_trr;
                                }
                                $trr_csv_data[] = $trr_hdata;

                                $trr_hdata = []; // reset record after loop getting all the details
                                $iteratedLineno++;
                            } else {
                                Log::info("EFGR : ".json_encode($item_mapping));
                            }
                        } // end of foreach($billing_information_dtl as $dtl => $val)

                        $document_number = rand();
                        $document_id = strtoupper(str_random(9));
                        $pdf_filename = 'POD_'.$shipment_delivery->account_group_store_id.
                        '_'.date("Ymdhis", strtotime(Carbon::now()->toDateTimeString())).
                        '.pdf';
                        sort($details);//TO MAKE SURE BATCHED ITEMS GO AFTER ANOTHER

                        $hold = 0;
                        $holdQty = 0;
                        $holdRtl = 0;
                        $holdCost = 0;
                        $newArray = array();
                        foreach($details as $key => $value){
                            if($hold == $value['item_code']){
                                $holdQty += $value['quantity_received'];
                                $holdRtl += $value['retail_price'];
                                $holdCost += $value['unit_cost'];
                                $newArray[sizeOf($newArray)-1]['quantity_received'] = $holdQty;
                                $newArray[sizeOf($newArray)-1]['retail_price'] = $holdRtl;
                                $newArray[sizeOf($newArray)-1]['unit_cost'] = $holdCost;
                                continue;
                            }
                            $newArray[] = $details[$key];
                            $hold = $value['item_code'];
                            $holdQty = $value['quantity_received'];
                            $holdRtl = $value['retail_price'];
                            $holdCost = $value['unit_cost'];
                        }
                        
                        Log::info("--- DETAILS: ".json_encode($newArray));
                        $pdf_data = [
                            'shipment_delivery_uuid' => $shipment_delivery->shipment_delivery_uuid,
                            'store_name' => $shipment_delivery->store_name,
                            'ship_to_code' => $shipment_delivery->account_group_store_id,
                            'delivery_date' => date('m/d/Y', strtotime($shipment_delivery->delivery_date)),
                            'print_date' => date('m/d/Y H:i A', strtotime(Carbon::now()->toDateTimeString())),
                            'document_id' => $document_id,
                            'document_number' => $pod_invoice_number, 
                            'truck_personnel_name' => $truck_personnel->truck_personnel_name,
                            'helper_name' => $truck_team_helper,
                            'store_email_address' => ($shipment_delivery->store_email_address) ? $shipment_delivery->store_email_address : $store->store_email_address,
                            'store_personnel_name' => $shipment_delivery->store_personnel_name,
                            'store_personnel_signature_filename' => $shipment_delivery->store_personnel_signature_filename,
                            'store_personnel_image_filename' => $shipment_delivery->store_personnel_image_filename,
                            'delivery_items' => $newArray,
                            'filename' => $pdf_filename,
                        ];

                        $update_pod = Pod::whereShipmentDeliveryId($shipment_delivery->shipment_delivery_id)->first();
                        if ($update_pod) {
                            $update_pod->pod_pdf_filename = $pdf_filename;
                            $update_pod->document_number = $document_number;
                            $update_pod->document_id = $document_id;
                            $update_pod->updated_at = Carbon::now()->toDateTimeString();
                            //$update_pod->save();

                            if ($update_pod->save()) {
                                PdfHelper::generate_pdf($pdf_data);
                            }
                        }
                        foreach(config('app.db_dlv_tail_fields') as $index => $field) {

                            if ($field == 'T') {
                                $value = 'T';
                            }
                            elseif($field == 'Supplier') {
                                $value = 'SD1';
                            }
                            elseif($field == 'Delivery Date') {
                                //$value = ($billing_header) ? date('Y-m-d',strtotime($billing_header->delivery_date)) : '0000-00-00';
                                // Force to use invoice date instead
                                $value = ($billing_header) ? date('Y-m-d', strtotime('-1 day', strtotime($billing_header->invoice_date))) : '0000-00-00';
                            }
                            elseif($field == 'Store') {
                                $value = $store->account_group_store_id;
                            }
                            elseif($field == 'PO Number') {
                                //$value = ($billing_header) ? $billing_header->customer_po_reference_number : '0';

                                if (!empty($billing_header->customer_po_reference_number)) {
                                    $value = $billing_header->customer_po_reference_number;
                                } else {
                                    $value = (string) "0";
                                }
                            }
                            elseif($field == 'Total Items') {
                                $value = $count_total_rows;
                            }
                            elseif($field == 'Constant Value') {
                                $value = '1';
                            } else {
                                $value = '';
                            }
                            $tdata[] = $value;
                        }
                        $csv_data[] = $tdata;

                    }
                    Log::info("CSV Data : ".json_encode($csv_data));
                    //                        $csv_data[] = $hdata;
                    //                        $csv_data[] = $ddata;
                    //                        $csv_data[] = $tdata;
                    //                        //Create DLV file

                    if(!in_array((string)$sold_to_code, config('app.efgr'))) {//EFGR
                        try {
                            $file_type = 'DLV';
                            //DLV Creation
                            $filename_dlv = 'DLV.SC'.$store->account_group_store_id.date("md", strtotime($billing_header->invoice_date)).
                            '8'.$seq_per_day;
                            Log::info("DLV Filename : ".$filename_dlv);
                            $file_path_dlv = Excel::create($filename_dlv, function($excel) use($csv_data) {
                                $excel->sheet('DLV', function($sheet) use($csv_data) {
                                    $sheet->fromArray($csv_data, null, 'A1', false, false);
                                });
                            })->store('csv', public_path().
                                '/uploads/csv/dlv', true);

                            FileHelper::sftpUploadFile($file_path_dlv['full'], $file_type, $filename_dlv);

                            //DRA Creation
                            /*$filename_dra = 'SC'.$store->account_group_store_id.date("md", strtotime($billing_header->invoice_date)).'8'.$seq_per_day.'.DRA';
                            Log::info("DRA Filename : ".$filename_dra);
                            $file_path_dra =  Excel::create($filename_dra, function($excel) use ($csv_data) {
                                    $excel->sheet('DRA', function($sheet) use ($csv_data)
                                    {
                                        $sheet->fromArray($csv_data, null, 'A1', false, false);
                                    });
                                })->store('csv', public_path().'/uploads/csv/dlv',true);

                            FileHelper::sftpUploadFile($file_path_dra['full'], $file_type, $filename_dra);*/
                            //                                   if($update_pod)
                            //                                       PdfHelper::generate_pdf($pdf_data);

                            
                            $filename_dra = 'TRR.SC'.$store->account_group_store_id.date("mdy", strtotime($billing_header->invoice_date));
                            Log::info("TRR Filename : ".$filename_dra);
                            $file_path_dra = Excel::create($filename_dra, function($excel) use($trr_csv_data) {
                                $excel->sheet('TRR', function($sheet) use($trr_csv_data) {
                                    $sheet->fromArray($trr_csv_data, null, 'A1', false, false);
                                });
                            })->store('csv', public_path().
                                '/uploads/csv/trr', true);

                            FileHelper::sftpUploadFile($file_path_dra['full'], 'TRR', $filename_dra);

                        } catch (Exception $e) {
                            // Nothing to do
                            Log::info("DLV Not saved on DB");
                        }
                    }

                    $response = [
                        'code' => '201',
                        'status' => "SC001",
                        'message' => "Successfully sync Billing information"
                    ];
                    return $response;
                }
            }
        }
    }
}
